<?php

namespace App\Services\Graduation\Exceptions;

use RuntimeException;

/**
 * Попытка удалить запись учёта бланков строгой отчётности.
 *
 * Бросается моделями `DiplomaBlank`, `DiplomaBlankBatch` и `DiplomaBlankEvent`.
 * Отдельное исключение, а не общий `RuntimeException`: удаление здесь не ошибка
 * ввода, а нарушение правила учёта, и в журнале это должно читаться именно так.
 */
class StrictReportingRecordIsNeverDeleted extends RuntimeException
{
}
