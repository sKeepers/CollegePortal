<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Graduate;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PersonPhotoController extends Controller
{
    private const TYPES = [
        'students' => Student::class,
        'teachers' => Teacher::class,
        'graduates' => Graduate::class,
    ];

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'type' => ['nullable', Rule::in(array_keys(self::TYPES))],
        ]);

        $person = $this->resolvePerson($type, $id);
        $this->deleteExistingPhoto($person);

        $path = $request->file('photo')->store("person-photos/{$type}", 'public');
        $person->forceFill(['photo_path' => $path])->save();

        return response()->json([
            'message' => 'Фото обновлено.',
            'data' => [
                'photo_path' => $path,
                'photo_url' => Storage::disk('public')->url($path),
            ],
        ]);
    }

    public function destroy(string $type, int $id): Response
    {
        $person = $this->resolvePerson($type, $id);
        $this->deleteExistingPhoto($person);
        $person->forceFill(['photo_path' => null])->save();

        return response()->noContent();
    }

    private function resolvePerson(string $type, int $id): Model
    {
        abort_unless(isset(self::TYPES[$type]), Response::HTTP_NOT_FOUND);

        /** @var class-string<Model> $modelClass */
        $modelClass = self::TYPES[$type];

        return $modelClass::query()->findOrFail($id);
    }

    private function deleteExistingPhoto(Model $person): void
    {
        $path = $person->getAttribute('photo_path');
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
