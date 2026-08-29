<?php

namespace App\Services;

use App\Models\RfidCardIssue;
use App\Support\Time\CollegeTime;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Журнал выдачи карт книгой Excel.
 *
 * То же, что печатает комендант, только файлом: заголовок с периодом, строки
 * журнала и пустой столбец для подписи. Подпись оставлена пустой намеренно —
 * ведомость подписывают на бумаге, а не в файле.
 */
class RfidCardJournalExport
{
    private const HEADERS = [
        '№',
        'Выдана',
        'Фамилия, имя, отчество',
        'Группа / подразделение',
        'Номер карты',
        'Выдал',
        'Закрыта',
        'Причина',
        'Подпись',
    ];

    /**
     * @param  Collection<int, RfidCardIssue>  $issues
     * @return array{filename: string, content: string}
     */
    public function build(Collection $issues, string $title, string $period): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Журнал выдачи');

        $lastColumn = 'I';
        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->setCellValue('A2', $period);
        $sheet->mergeCells("A2:{$lastColumn}2");

        $sheet->fromArray(self::HEADERS, null, 'A4');
        $sheet->getStyle("A4:{$lastColumn}4")->getFont()->setBold(true);

        $row = 5;
        foreach ($issues->values() as $index => $issue) {
            $person = $issue->person;
            $student = $person?->primaryStudent;
            $employee = $person?->primaryEmployee;

            $sheet->fromArray([
                $index + 1,
                CollegeTime::forDisplay($issue->issued_at)?->format('d.m.Y H:i'),
                trim(implode(' ', array_filter([
                    $person?->last_name,
                    $person?->first_name,
                    $person?->middle_name,
                ]))),
                $student?->group?->name ?? $employee?->primaryDepartment?->name,
                // Текстом, иначе Excel съедает ведущие нули и номер карты
                // превращается в число, по которому карта не находится.
                (string) $issue->card?->uid,
                $issue->issuedBy?->name,
                $issue->returned_at === null ? 'на руках' : CollegeTime::forDisplay($issue->returned_at)->format('d.m.Y H:i'),
                RfidCardIssue::reasonLabel($issue->close_reason) ?? '—',
                '',
            ], null, 'A'.$row);

            $sheet->getCell('E'.$row)->setValueExplicit(
                (string) $issue->card?->uid,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
            );

            $row++;
        }

        $lastRow = max($row - 1, 4);
        $sheet->getStyle("A4:{$lastColumn}{$lastRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        $sheet->getStyle("A4:{$lastColumn}{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        // Подпись автоширине не поддаётся: столбец пустой, и она сожмёт его в
        // ничто, а расписываться надо в чём-то.
        $sheet->getColumnDimension('I')->setAutoSize(false);
        $sheet->getColumnDimension('I')->setWidth(28);

        $sheet->freezePane('A5');

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        ob_start();
        $writer->save('php://output');

        return [
            'filename' => 'collegeportal_rfid_journal_'.now()->format('Y-m-d').'.xlsx',
            'content' => ob_get_clean() ?: '',
        ];
    }
}
