<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class CreateController extends Controller
{
    public function index(): View
    {
        return view('pages.create.index');
    }
}
