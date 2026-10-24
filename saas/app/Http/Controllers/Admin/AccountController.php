<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Usage\UsageMeter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __construct(private readonly UsageMeter $meter) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'monthly_token_budget' => ['nullable', 'integer', 'min:0'],
        ]);
        $account = Account::create($data);

        return redirect()->route('admin.accounts.show', $account)->with('status', 'Account created.');
    }

    public function show(Request $request, Account $account): View
    {
        $report = DashboardController::report($request, $account->id);

        return view('admin.account', [
            'account' => $account,
            'report' => $report,
            'totals' => $report->totals(),
            'summary' => $this->meter->summary($account),
            'sites' => $account->sites()->orderBy('name')->get(),
            'bySite' => collect($report->by('site_id'))->keyBy('key'),
            'byModel' => $report->by('model'),
            'byPurpose' => $report->by('purpose'),
            'recent' => $report->query()->with('site')->latest('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'monthly_token_budget' => ['nullable', 'integer', 'min:0'],
        ]);
        $account->update($data);

        return back()->with('status', 'Account saved.');
    }

    /**
     * Add or refund tokens by hand. The ledger keeps it as its own row.
     */
    public function adjust(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'tokens' => ['required', 'integer', 'not_in:0', 'between:-1000000000,1000000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $this->meter->adjust($account, (int) $data['tokens'], $data['reason'].' (by '.$request->user()->email.')');

        return back()->with('status', 'Adjustment recorded.');
    }
}
