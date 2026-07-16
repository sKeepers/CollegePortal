# Access Control Privacy

## QR

QR содержит только короткоживущий технический token. В QR не записываются:

- ФИО;
- группа;
- телефон;
- email;
- адрес;
- паспортные данные;
- СНИЛС;
- документы.

## Scanner response

Оператор получает только минимум для принятия решения на проходной:

- allowed / denied;
- display name;
- category: student / teacher / employee;
- фото при наличии и разрешении;
- группа или подразделение кратко;
- причина отказа;
- timestamp.

## Logs and audit

Raw token не должен попадать в logs, audit payload, GitHub issues, screenshots или документацию. Для расследований используются event id, request id, person id и reason code.
