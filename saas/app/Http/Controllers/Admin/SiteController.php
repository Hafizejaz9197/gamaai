<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Registers sites and rotates their plugin tokens. The plain token is
 * rendered in the response once and never stored or put in the session.
 */
class SiteController extends Controller
{
    public function store(Request $request, Account $account): Response
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:http,https', 'max:255'],
        ]);

        $site = new Site($data);
        $site->account()->associate($account);
        $token = $site->issueToken();
        $site->save();

        return $this->showToken($site, $token, rotated: false);
    }

    /**
     * Give the site a new token. The old one stops working at once.
     */
    public function rotate(Site $site): Response
    {
        $token = $site->issueToken();
        $site->save();

        return $this->showToken($site, $token, rotated: true);
    }

    private function showToken(Site $site, string $token, bool $rotated): Response
    {
        return response()
            ->view('admin.token', ['site' => $site, 'token' => $token, 'rotated' => $rotated])
            ->header('Cache-Control', 'no-store');
    }
}
