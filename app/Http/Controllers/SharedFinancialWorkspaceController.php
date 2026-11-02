<?php

namespace App\Http\Controllers;

use App\Services\SharedFinancialWorkspaceService;
use App\Notifications\SharedWorkspaceInvitationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SharedFinancialWorkspaceController extends Controller
{
    public function index(Request $request, SharedFinancialWorkspaceService $service)
    {
        return response()->json(['data' => $service->list($request->user())]);
    }

    public function store(Request $request, SharedFinancialWorkspaceService $service)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        return response()->json(['data' => $service->create($request->user(), $data['name'])], 201);
    }

    public function show(Request $request, int $workspace, SharedFinancialWorkspaceService $service)
    {
        return response()->json(['data' => $service->snapshot($request->user(), $workspace)]);
    }

    public function invite(Request $request, int $workspace, SharedFinancialWorkspaceService $service)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $invitation = $service->invite($request->user(), $workspace, $data['email']);
        $frontendUrl = rtrim(config('app.frontend_url', 'https://ledger.fdevsite.cloud'), '/');
        $frontendHost = strtolower((string) parse_url($frontendUrl, PHP_URL_HOST));
        if (app()->environment('production') && in_array($frontendHost, ['localhost', '127.0.0.1', '::1'], true)) {
            $frontendUrl = 'https://ledger.fdevsite.cloud';
        }
        $inviteUrl = $frontendUrl.'/kolaborasi?invite='.urlencode($invitation['invite_token']);
        $invitation['invite_url'] = $inviteUrl;
        try {
            Notification::route('mail', $invitation['email'])->notify(new SharedWorkspaceInvitationNotification(
                $invitation['workspace_name'], $inviteUrl, $request->user()->name,
            ));
            $invitation['email_sent'] = true;
        } catch (\Throwable $error) {
            Log::warning('Shared workspace invitation email could not be sent.', ['invitation_id' => $invitation['id'], 'exception' => $error::class]);
            $invitation['email_sent'] = false;
        }
        return response()->json(['data' => $invitation], 201);
    }

    public function revokeInvitation(Request $request, int $workspace, int $invitation, SharedFinancialWorkspaceService $service)
    {
        $service->revokeInvitation($request->user(), $workspace, $invitation);
        return response()->json(['message' => 'Undangan berhasil dibatalkan.']);
    }

    public function pendingInvitations(Request $request, SharedFinancialWorkspaceService $service)
    {
        return response()->json(['data' => $service->pendingInvitations($request->user())]);
    }

    public function acceptInvitation(Request $request, SharedFinancialWorkspaceService $service)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        return response()->json(['data' => $service->acceptInvitation($request->user(), $data['token'])]);
    }

    public function acceptPendingInvitation(Request $request, int $invitation, SharedFinancialWorkspaceService $service)
    {
        return response()->json(['data' => $service->acceptPendingInvitation($request->user(), $invitation)]);
    }

    public function contribute(Request $request, int $workspace, SharedFinancialWorkspaceService $service)
    {
        $data = $request->validate([
            'source_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'entry_date' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);
        return response()->json(['data' => $service->contribute($request->user(), $workspace, $data)], 201);
    }

    public function createAsset(Request $request, int $workspace, SharedFinancialWorkspaceService $service)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'provider' => ['nullable', 'string', 'max:160']]);
        return response()->json(['data' => $service->createAsset($request->user(), $workspace, $data)], 201);
    }

    public function createGoal(Request $request, int $workspace, SharedFinancialWorkspaceService $service)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'target_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'target_date' => ['nullable', 'date'],
        ]);
        return response()->json(['data' => $service->createGoal($request->user(), $workspace, $data)], 201);
    }

    public function allocateGoal(Request $request, int $workspace, int $goal, SharedFinancialWorkspaceService $service)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['allocate', 'release'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'allocation_date' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:500'], 'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);
        return response()->json(['data' => $service->allocateGoal($request->user(), $workspace, $goal, $data)], 201);
    }

    public function transactAsset(Request $request, int $workspace, int $asset, SharedFinancialWorkspaceService $service)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['purchase', 'sale', 'valuation'])],
            'amount' => ['required_if:action,purchase,sale', 'nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'quantity' => ['required_if:action,purchase,sale', 'nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:999999999.99999999'],
            'unit_price' => ['required_if:action,valuation', 'nullable', 'numeric', 'gt:0', 'decimal:0,8', 'max:9999999999999999.99999999'],
            'fees' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:9999999999999.99'],
            'transaction_date' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);
        return response()->json(['data' => $service->transactAsset($request->user(), $workspace, $asset, $data)], 201);
    }

    public function withdraw(Request $request, int $workspace, SharedFinancialWorkspaceService $service)
    {
        $data = $request->validate([
            'destination_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'entry_date' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:500'], 'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);
        return response()->json(['data' => $service->withdraw($request->user(), $workspace, $data)], 201);
    }

    public function removeMember(Request $request, int $workspace, int $member, SharedFinancialWorkspaceService $service)
    {
        $service->removeMember($request->user(), $workspace, $member);
        return response()->json(['message' => 'Anggota berhasil dikeluarkan.']);
    }
}
