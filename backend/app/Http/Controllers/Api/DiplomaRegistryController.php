<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Graduation\DiplomaRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Книга регистрации выданных дипломов.
 *
 * Отдаёт строки книги, а печатает их браузер: лист собирается тем же способом,
 * что ведомость выдачи карт — отдельным документом со своими стилями, а не
 * текущей страницей.
 *
 * Права: `graduation.view`, а не право на бланки. Книгу читают шире, чем ведут
 * склад, и человеку, который отвечает на запрос о подлинности диплома, склад не
 * нужен.
 */
class DiplomaRegistryController extends Controller
{
    public function __construct(private readonly DiplomaRegistryService $registry)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('graduation_year') ?: null;

        $rows = $this->registry->rows($year);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $rows->count(),
                'graduation_year' => $year,
                // Годы, за которые в книге вообще есть строки: экран строит из
                // них выбор, а не гадает по диапазону.
                'years' => $this->registry->years(),
            ],
        ]);
    }
}
