<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
        public function __construct()
    {
        //
    }

    public function index()
    {
        return response()->json(['message' => 'Welcome to the API']);
    }
}
