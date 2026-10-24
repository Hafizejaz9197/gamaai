<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Site;
use App\Services\Usage\UsageMeter;
use App\Services\Usage\UsageReport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Spend for one month across all accounts: by account, site, model and
 * request type, with estimated and provider-confirmed cost side by side.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly UsageMeter $meter) {}

    public function index(Request $request): View
    {
        $report = self::report($request);
        $byAccount = collect($report->by('account_id'))->keyBy('key');
        $accounts = Account::withCount('sites')->orderBy('name')->get();

        return view('admin.dashboard', [
            'report' => $report,
            'totals' => $report->totals(),
            'releases' => $report->releases(),
            'accounts' => $accounts->map(fn (Account $account) => [
                'account' => $account,
                'usage' => $byAccount->get($account->id),
                'summary' => $this->meter->summary($account),
            ]),
            'bySite' => $report->by('site_id'),
            'sites' => Site::with('account')->get()->keyBy('id'),
            'byModel' => $report->by('model'),
            'byPurpose' => $report->by('purpose'),
        ]);
    }

    /**
     * The month asked for in ?month=YYYY-MM, or this month.
     */
    public static function report(Request $request, ?int $accountId = null): UsageReport
    {
        try {
            return UsageReport::forMonth($request->query('month'), $accountId);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }
}
