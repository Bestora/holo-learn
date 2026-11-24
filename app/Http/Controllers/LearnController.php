<?php

namespace App\Http\Controllers;

use App\Learning\LessonFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LearnController extends Controller
{
    public function __construct(private LessonFlow $flow) {}

    public function index(Request $request): Response
    {
        return Inertia::render('learn/index', $this->flow->show($request->user()));
    }

    public function start(Request $request): RedirectResponse
    {
        $this->flow->dismissSummary($request->user());

        return to_route('learn.index');
    }

    public function intro(Request $request): RedirectResponse
    {
        $data = $request->validate(['item_id' => ['required', 'integer']], self::MESSAGES);
        $this->flow->confirmIntro($request->user(), (int) $data['item_id']);

        return to_route('learn.index');
    }

    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'answer' => ['present', 'nullable', 'string', 'max:200'],
        ], self::MESSAGES);

        return response()->json($this->flow->answer($request->user(), (int) $data['item_id'], (string) $data['answer']));
    }

    public function override(Request $request): JsonResponse
    {
        return response()->json($this->flow->override($request->user()));
    }

    private const MESSAGES = [
        'item_id.required' => 'The card is missing.',
        'item_id.integer' => 'Invalid card.',
        'answer.present' => 'The answer is missing.',
        'answer.string' => 'The answer must be text.',
        'answer.max' => 'The answer is too long.',
    ];
}
