<?php

namespace App\Http\Controllers;

use App\Enums\RosterStatus;
use App\Models\Roster;
use App\Services\RosterDocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class RosterPdfController extends Controller
{
    public function __invoke(int $year, int $month, RosterDocumentService $document): Response
    {
        $roster = Roster::query()->where('year', $year)->where('month', $month)->firstOrFail();
        $filename = sprintf('doctor-roster-%04d-%02d%s.pdf', $year, $month, $roster->status === RosterStatus::Draft ? '-draft' : '');

        return Pdf::loadView('rosters.document', [...$document->build($roster), 'print_button' => false])
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }
}
