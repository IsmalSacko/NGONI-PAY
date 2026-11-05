<?php

declare(strict_types=1);

namespace App\Support\Console;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * La période du suivi d'un compte dans la Console Ngoni (contrat v1.6) :
 * aujourd'hui, 7 jours, ce mois, depuis le début, ou du … au … ; la période
 * d'avant pour comparer, et les créneaux de la courbe (heure, jour ou mois).
 * Sans période : les 30 derniers jours (contrat v1.5).
 */
final class PeriodeSuivi
{
    private const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    private function __construct(
        public readonly string $code,
        public readonly string $libelle,
        public readonly CarbonImmutable $du,
        public readonly CarbonImmutable $au,
        public readonly ?CarbonImmutable $avantDu,
        public readonly ?CarbonImmutable $avantAu,
        /** heure | jour | mois */
        public readonly string $pas,
    ) {}

    /**
     * @param  CarbonImmutable|null  $debut  la première activité du compte (sinon sa création), pour « depuis le début »
     *
     * @throws ValidationException période inconnue ou dates invalides
     */
    public static function depuis(?string $periode, ?string $du, ?string $au, ?CarbonImmutable $debut = null): self
    {
        $aujourdhui = CarbonImmutable::today();

        switch ($periode) {
            case null:
            case '':
                $d = $aujourdhui->subDays(29);

                return new self('30j', '30 derniers jours', $d, $aujourdhui, $d->subDays(30), $d->subDay(), 'jour');
            case 'jour':
                return new self('jour', 'Aujourd’hui', $aujourdhui, $aujourdhui, $aujourdhui->subDay(), $aujourdhui->subDay(), 'heure');
            case '7j':
                $d = $aujourdhui->subDays(6);

                return new self('7j', '7 derniers jours', $d, $aujourdhui, $d->subDays(7), $d->subDay(), 'jour');
            case 'mois':
                $d = $aujourdhui->startOfMonth();
                $avantDu = $d->subMonthNoOverflow();
                $avantAu = $avantDu->addDays($aujourdhui->day - 1)->min($avantDu->endOfMonth()->startOfDay());

                return new self('mois', 'Ce mois', $d, $aujourdhui, $avantDu, $avantAu, 'jour');
            case 'tout':
                $d = ($debut ?? $aujourdhui)->startOfDay()->min($aujourdhui);

                return new self('tout', 'Depuis le début', $d, $aujourdhui, null, null, 'mois');
            case 'perso':
                $de = self::date($du, 'du');
                $a = self::date($au, 'au');
                if ($a->gt($aujourdhui)) {
                    self::refus('La date de fin ne peut pas être après aujourd’hui.');
                }
                if ($de->gt($a)) {
                    self::refus('La date de début doit venir avant la date de fin.');
                }
                if ($de->lt($a->subYears(3))) {
                    self::refus('Choisissez 3 ans au plus.');
                }
                $jours = (int) $de->diffInDays($a) + 1;
                $pas = $jours === 1 ? 'heure' : ($jours <= 62 ? 'jour' : 'mois');

                return new self('perso', 'Du '.$de->format('d/m/Y').' au '.$a->format('d/m/Y'), $de, $a, $de->subDays($jours), $de->subDay(), $pas);
            default:
                self::refus('Période inconnue : choisissez aujourd’hui, 7 jours, ce mois, depuis le début, ou des dates.');
        }
    }

    /** @return array{code: string, libelle: string, du: string, au: string, avant_du: ?string, avant_au: ?string} */
    public function versTableau(): array
    {
        return [
            'code' => $this->code, 'libelle' => $this->libelle,
            'du' => $this->du->toDateString(), 'au' => $this->au->toDateString(),
            'avant_du' => $this->avantDu?->toDateString(), 'avant_au' => $this->avantAu?->toDateString(),
        ];
    }

    /**
     * Les créneaux de la courbe, dans l'ordre : `cle` sert à ranger les chiffres
     * (heure « 0 »…« 23 », jour AAAA-MM-JJ, mois AAAA-MM).
     *
     * @return list<array{cle: string, date: string, libelle: string}>
     */
    public function creneaux(): array
    {
        $c = [];
        if ($this->pas === 'heure') {
            for ($h = 0; $h < 24; $h++) {
                $c[] = ['cle' => (string) $h, 'date' => $this->du->toDateString().sprintf('T%02d:00', $h), 'libelle' => $h.' h'];
            }
        } elseif ($this->pas === 'jour') {
            for ($d = $this->du; $d->lte($this->au); $d = $d->addDay()) {
                $c[] = ['cle' => $d->toDateString(), 'date' => $d->toDateString(), 'libelle' => $d->format('d/m')];
            }
        } else {
            $debut = $this->du->startOfMonth();
            if ($this->code === 'tout') {
                $debut = $debut->max($this->au->startOfMonth()->subMonthsNoOverflow(23));
            }
            for ($m = $debut; $m->lte($this->au); $m = $m->addMonthNoOverflow()) {
                $c[] = ['cle' => $m->format('Y-m'), 'date' => $m->toDateString(), 'libelle' => self::MOIS[$m->month - 1].' '.$m->year];
            }
        }

        return $c;
    }

    private static function date(?string $v, string $champ): CarbonImmutable
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            self::refus($champ === 'du' ? 'Choisissez une date de début valide.' : 'Choisissez une date de fin valide.');
        }

        return CarbonImmutable::parse($v)->startOfDay();
    }

    private static function refus(string $message): never
    {
        throw ValidationException::withMessages(['periode' => [$message]]);
    }
}
