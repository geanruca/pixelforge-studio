<?php

namespace Tests\Feature;

use App\Support\EngineeringNotes;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EngineeringNotesTest extends TestCase
{
    public function test_the_page_is_public(): void
    {
        $this->get(route('engineering'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Engineering')
                ->has('areas', count(EngineeringNotes::all()))
                ->where('repository', 'https://github.com/geanruca/pixelforge-studio'));
    }

    public function test_every_file_and_test_a_note_points_at_exists(): void
    {
        foreach (EngineeringNotes::all() as $area) {
            foreach ($area['notes'] as $note) {
                $this->assertNotEmpty($note['files'], "{$note['title']} names no file.");
                $this->assertNotEmpty($note['tests'], "{$note['title']} names no test.");

                foreach ([...$note['files'], ...$note['tests']] as $path) {
                    $this->assertFileExists(base_path($path), "{$note['title']} points at a file that does not exist.");
                }
            }
        }
    }
}
