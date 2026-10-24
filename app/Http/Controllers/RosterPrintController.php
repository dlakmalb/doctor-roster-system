<?php

namespace App\Http\Controllers;

use App\Models\Roster;
use App\Services\RosterDocumentService;
use Illuminate\Contracts\View\View;

class RosterPrintController extends Controller
{
    public function __invoke(int $year, int $month, RosterDocumentService $document): View
    {
        $roster = Roster::query()->where('year', $year)->where('month', $month)->firstOrFail();

        return view('rosters.document', [...$document->build($roster), 'print_button' => true]);
    }
}
