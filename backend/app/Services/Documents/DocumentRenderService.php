<?php

namespace App\Services\Documents;

use RuntimeException;
use ZipArchive;

class DocumentRenderService
{
    public function renderDocx(array $variables, string $targetPath): void
    {
        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0775, true);
        }

        $zip = new ZipArchive();
        $result = $zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new RuntimeException('Не удалось создать DOCX-файл документа.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rels());
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString('word/document.xml', $this->documentXml($variables));
        $zip->addFromString('word/styles.xml', $this->styles());
        $zip->close();
    }

    private function documentXml(array $v): string
    {
        $e = fn (string $key, string $fallback = '') => htmlspecialchars((string) ($v[$key] ?? $fallback), ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $p = fn (string $text) => '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8').'</w:t></w:r></w:p>';

        $body = '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t>СПРАВКА</w:t></w:r></w:p>';
        $body .= $p('№ '.$e('document.number').' от '.$e('document.issue_date'));
        $body .= $p($e('organization.full_name'));
        $body .= $p('Настоящая справка подтверждает, что '.$e('student.full_name').' обучается в '.$e('organization.short_name').' на '.$e('student.course').' курсе, группа '.$e('student.group').'.');
        $body .= $p('Форма обучения: '.$e('student.education_form').'. Форма финансирования: '.$e('student.funding_type').'.');
        $body .= $p('Специальность: '.$e('student.specialty_code').' '.$e('student.specialty_name').'.');
        $body .= $p('Приказ о зачислении: '.$e('student.enrollment_order.number', 'нет данных').' от '.$e('student.enrollment_order.date', 'нет данных').'.');
        $body .= $p('Справка сформирована для предъявления по месту требования.');
        $body .= $p('Проверка подлинности: '.$e('verification.url'));
        $body .= $p('');
        $body .= $p($e('signer.position', 'Руководитель').' __________________ / '.$e('signer.full_name').' /');
        $body .= $p('М.П.');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .$body
            .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr>'
            .'</w:body></w:document>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>';
    }

    private function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/></w:rPr></w:style></w:styles>';
    }
}
