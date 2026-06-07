<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function index(Request $request): View
    {
        $query = SupportMessage::with('user')->latest();

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($q = trim($request->string('q')->toString())) {
            $query->where(function ($w) use ($q) {
                $w->where('email', 'ilike', "%{$q}%")
                  ->orWhere('body', 'ilike', "%{$q}%");
            });
        }

        $messages = $query->paginate(25)->appends($request->query());

        $counts = SupportMessage::selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')->pluck('c', 'status')->toArray();

        return view('pages.admin.support.index', compact('messages', 'counts'));
    }

    public function show(SupportMessage $message): View
    {
        $message->load('user');
        // Auto-mark 'open' messages as 'read' the first time an admin opens them.
        if ($message->status === 'open') {
            $message->update(['status' => 'read']);
        }
        return view('pages.admin.support.show', compact('message'));
    }

    public function update(Request $request, SupportMessage $message): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,read,replied'],
        ]);
        $message->update($data);
        return back()->with('status', 'Status updated.');
    }

    public function destroy(SupportMessage $message): RedirectResponse
    {
        $message->delete();
        return redirect()->route('admin.support.index')->with('status', 'Deleted.');
    }
}
