<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\UsageEntry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The raw usage ledger, newest first.
 */
class LedgerController extends Controller
{
    public const KINDS = [UsageEntry::RESERVE, UsageEntry::SETTLE, UsageEntry::RELEASE, UsageEntry::ADJUST];

    public function index(Request $request): View
    {
        $accountId = $request->integer('account') ?: null;
        $kind = in_array($request->query('kind'), self::KINDS, true) ? $request->query('kind') : null;
        $report = DashboardController::report($request, $accountId);

        return view('admin.ledger', [
            'report' => $report,
            'accounts' => Account::orderBy('name')->get(),
            'accountId' => $accountId,
            'kind' => $kind,
            'entries' => $report->query()
                ->with(['account', 'site'])
                ->when($kind, fn ($q) => $q->where('kind', $kind))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }
}
