<?php

namespace App\Http\Controllers;

use App\Models\SmartAssistantMessage;
use App\Services\SmartAssistantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SmartAssistantController extends Controller
{
    public function index(Request $request): View
    {
        return view('system.smart-assistant', [
            'messages' => SmartAssistantMessage::query()
                ->where('user_id', $request->user()->id)
                ->latest('id')
                ->limit(30)
                ->get()
                ->reverse()
                ->values(),
        ]);
    }

    public function ask(Request $request, SmartAssistantService $assistant): RedirectResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:1000'],
        ]);

        SmartAssistantMessage::create([
            'user_id' => $request->user()->id,
            'role' => 'user',
            'content' => $data['question'],
        ]);

        $result = $assistant->answer($data['question']);

        SmartAssistantMessage::create([
            'user_id' => $request->user()->id,
            'role' => 'assistant',
            'content' => $result['answer'],
            'meta' => [
                'topic' => $result['topic'],
                'facts' => $result['facts'],
                'engine' => 'businessos-local',
            ],
        ]);

        return back();
    }

    public function clear(Request $request): RedirectResponse
    {
        SmartAssistantMessage::query()
            ->where('user_id', $request->user()->id)
            ->delete();

        return back()->with('status', __('assistant.history_cleared'));
    }
}
