<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class LandingController extends Controller
{
    public function index(): Response
    {
        abort(501, 'Landing page ainda não implementada.');
    }
}
