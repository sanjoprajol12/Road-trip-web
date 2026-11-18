<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmergencyContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Private to each rider. Looked up through the owner, so other riders' ids are never found. */
class EmergencyContactController extends Controller
{
    private function rules(): array
    {
        return ['name' => ['required', 'string', 'min:2', 'max:120'], 'phone' => ['required', 'string', 'regex:/^[0-9+()\-\s]{5,40}$/'], 'relation' => ['nullable', 'string', 'max:60']];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => EmergencyContact::where('user_id', $request->user()->id)->orderBy('created_at')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(EmergencyContact::where('user_id', $request->user()->id)->count() >= 10, 422, 'Up to 10 emergency contacts.');
        $c = new EmergencyContact($request->validate($this->rules(), ['phone.regex' => 'Use digits, spaces, +, - or brackets.']));
        $c->user_id = $request->user()->id;
        $c->save();

        return response()->json(['data' => $c], 201);
    }

    public function update(Request $request, string $contact): JsonResponse
    {
        $c = EmergencyContact::where('user_id', $request->user()->id)->findOrFail($contact);
        $c->update($request->validate($this->rules()));

        return response()->json(['data' => $c]);
    }

    public function destroy(Request $request, string $contact): JsonResponse
    {
        EmergencyContact::where('user_id', $request->user()->id)->findOrFail($contact)->delete();

        return response()->json(null, 204);
    }
}
