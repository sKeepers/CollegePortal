# Официальные источники ФИС API

Задача: FIS-API-001.

## Источник

- Официальная страница: <https://priem.rustest.ru/instructions>
- Ожидаемые материалы: тестовый клиент, спецификация сервиса автоматизированного взаимодействия, XSD метода импорта, обзоры изменений, рекомендации по ошибкам.

## Результат проверки 13.07.2026

С DEV-сервера `/srv/college-dev` официальный сайт недоступен для автоматической загрузки:

- `curl https://priem.rustest.ru/instructions` завершился ошибкой проверки TLS CA: `unable to get local issuer certificate`.
- `curl -k https://priem.rustest.ru/instructions` вернул `Forbidden` с request id.
- Без JS обычный browser fetch показывает SPA-заглушку, а не список материалов.

Вывод: нужна загрузка материалов через разрешенный браузер/АРМ/шлюз, либо настройка доверенной TLS-цепочки и доступа к сайту. Это не дефект CollegePortal.

## Скрипт загрузки

Подготовлен скрипт:

```bash
scripts/fis/download-official-specs.sh
```

Он сохраняет материалы и manifest в private storage:

```text
backend/storage/app/private/reference/fis/
```

Эта папка добавлена в `.gitignore`, потому что официальные документы и XSD могут иметь ограничения на распространение.

## Manifest

Manifest должен хранить:

- название;
- версию;
- дату;
- URL;
- SHA-256;
- дату загрузки;
- статус загрузки.

Текущий статус: `READY FOR OFFICIAL SPECS`.
