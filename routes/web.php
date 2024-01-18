<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Backend as Backend;




Route::resource('/', Backend\StarterController::class);