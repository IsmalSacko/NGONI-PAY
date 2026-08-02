<?php

test('the root route redirects guests to the admin login page', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
