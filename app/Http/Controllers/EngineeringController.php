<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\EngineeringNotes;
use Inertia\Inertia;
use Inertia\Response;

class EngineeringController extends Controller
{
    private const string REPOSITORY = 'https://github.com/geanruca/pixelforge-studio';

    public function __invoke(): Response
    {
        return Inertia::render('Engineering', [
            'areas' => EngineeringNotes::all(),
            'repository' => self::REPOSITORY,
        ]);
    }
}
