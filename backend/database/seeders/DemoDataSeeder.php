<?php

namespace Database\Seeders;

use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\Attendance;
use App\Models\ApplicantApplication;
use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\CurriculumSubject;
use App\Models\DigitalIdentity;
use App\Models\Department;
use App\Models\EducationProgram;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Grade;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\Person;
use App\Models\Position;
use App\Models\Role;
use App\Models\ScheduleEntry;
use App\Models\ScheduleLesson;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use App\Models\User;
use App\Services\SettingService;
use App\Services\TeachingLoadGenerationService;
use Database\Seeders\Support\DemoNameFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    private const STUDENT_COUNT = 600;
    private const TEACHER_COUNT = 70;
    private const GROUP_COUNT = 30;
    private const DEMO_DOMAIN = 'demo.college.local';

    /** Сколько прошедших дней наполняется занятиями, журналом и проходами. */
    private const HISTORY_DAYS = 14;

    private DemoNameFactory $names;

    /**
     * Порядковый номер демонстрационного человека по идентификатору строки.
     * Поведение — успеваемость, привычка приходить вовремя, провальная
     * дисциплина — выводится из него, а не из идентификатора: см. пояснение
     * у `studentProfile`.
     *
     * @var array<int, int>
     */
    private array $studentOrdinals = [];

    /** @var array<int, int> */
    private array $teacherOrdinals = [];

    /** @var array<int, int> */
    private array $subjectOrdinals = [];

    public function run(): void
    {
        $this->names = new DemoNameFactory();

        DB::transaction(function (): void {
            $adminRole = Role::where('code', 'admin')->firstOrFail();
            $teacherRole = Role::where('code', 'teacher')->firstOrFail();
            $studentRole = Role::where('code', 'student')->firstOrFail();
            $demoPassword = env('DEMO_USER_PASSWORD', 'test1234');

            $this->writeRow(
                User::query(),
                ['email' => 'admin@local'],
                [
                    'role_id' => $adminRole->id,
                    'name' => 'Администратор',
                    'password' => Hash::make($demoPassword),
                    'is_active' => true,
                ]
            );

            $programs = $this->seedApplicantPrograms();
            $subjects = $this->seedSubjects();
            $classrooms = $this->seedClassrooms();
            $hr = $this->seedHrReferences();
            $teachers = $this->seedTeachers($teacherRole, $demoPassword, $hr);
            $groups = $this->seedGroups($programs, $teachers);
            $students = $this->seedStudents($studentRole, $demoPassword, $groups);

            $this->seedTeacherSubjects($teachers, $subjects);
            $curricula = $this->seedCurricula($programs, $subjects);
            $this->attachCurriculaToGroups($groups, $curricula);
            $lessons = $this->seedWeeklySchedule($groups, $teachers, $subjects, $classrooms);
            $marks = $this->seedJournalSamples($lessons, $students);
            $this->seedJournalEngine($lessons, $marks);
            // Отметки больше не нужны: держать их до конца наполнения значит
            // нести двадцать пять тысяч строк через все оставшиеся шаги.
            unset($marks);
            $this->seedTeachingLoads($groups, $teachers);
            $this->seedScheduleEntries($lessons);
            $this->seedGraduates($groups, $students);
            $this->seedDigitalIdentities($students, $teachers);
            $this->seedAccessEvents($students, $teachers, $lessons);
            $this->seedApplicantApplications($programs);
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, EducationProgram>
     */
    private function seedApplicantPrograms()
    {
        $educationLevel = 'Среднее профессиональное образование - программа подготовки специалистов среднего звена';
        $programs = [
            ['53.02.02', 'Музыкальное искусство эстрады', 'Артист, преподаватель, руководитель эстрадного коллектива', 'ППССЗ Музыкальное искусство эстрады', 'Очная', 3.8],
            ['53.02.03', 'Инструментальное исполнительство', 'Артист, преподаватель, концертмейстер', 'ППССЗ Инструментальное исполнительство', 'Очная', 3.8],
            ['53.02.04', 'Вокальное искусство', 'Артист-вокалист, преподаватель', 'ППССЗ Вокальное искусство', 'Очная', 3.8],
            ['53.02.05', 'Сольное и хоровое народное пение', 'Артист-вокалист, преподаватель, руководитель народного коллектива', 'ППССЗ Сольное и хоровое народное пение', 'Очная', 3.8],
            ['53.02.06', 'Хоровое дирижирование', 'Дирижер хора, преподаватель', 'ППССЗ Хоровое дирижирование', 'Очная', 3.8],
            ['53.02.07', 'Теория музыки', 'Преподаватель, организатор музыкально-просветительской деятельности', 'ППССЗ Теория музыки', 'Очная', 3.8],
            ['51.02.01', 'Народное художественное творчество', 'Руководитель любительского творческого коллектива, преподаватель', 'ППССЗ Народное художественное творчество', 'Очная', 3.8],
            ['51.02.02', 'Социально-культурная деятельность', 'Менеджер социально-культурной деятельности', 'ППССЗ Социально-культурная деятельность после 9 класса', 'Очная', 3.8],
            ['51.02.02', 'Социально-культурная деятельность', 'Менеджер социально-культурной деятельности', 'ППССЗ Социально-культурная деятельность после 9 класса', 'Заочная', 3.8],
            ['51.02.02', 'Социально-культурная деятельность', 'Менеджер социально-культурной деятельности', 'ППССЗ Социально-культурная деятельность после 11 класса', 'Заочная', 2.8],
            ['51.02.03', 'Библиотечно-информационная деятельность', 'Специалист по библиотечно-информационной деятельности', 'ППССЗ Библиотечно-информационная деятельность', 'Очная', 2.8],
            ['51.02.03', 'Библиотечно-информационная деятельность', 'Специалист по библиотечно-информационной деятельности', 'ППССЗ Библиотечно-информационная деятельность', 'Заочная', 2.8],
        ];

        return collect($programs)->map(function (array $item) use ($educationLevel): EducationProgram {
            [$code, $specialtyName, $qualification, $programName, $studyForm, $studyYears] = $item;
            $specialty = $this->writeRow(
                Specialty::query(),
                ['code' => $code],
                [
                    'name' => $specialtyName,
                    'education_level' => $educationLevel,
                    'qualification' => $qualification,
                    'normative_study_years' => $studyYears,
                    'description' => 'Демонстрационная специальность для DEV-стенда.',
                ]
            );

            return $this->writeRow(
                EducationProgram::query(),
                [
                    'specialty_id' => $specialty->id,
                    'name' => $programName,
                    'year_start' => 2026,
                    'study_form' => $studyForm,
                ],
                [
                    'study_years' => $studyYears,
                    'is_active' => true,
                    'description' => 'Демонстрационная образовательная программа без реальных персональных данных.',
                ]
            );
        })->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Subject>
     */
    private function seedSubjects()
    {
        $items = [
            'Сольфеджио', 'Музыкальная литература', 'Специальность', 'Фортепиано', 'Ансамбль',
            'Хоровой класс', 'История искусств', 'Гармония', 'Безопасность жизнедеятельности',
            'Иностранный язык', 'Русский язык', 'Литература', 'Информатика', 'Физическая культура',
            'Основы философии', 'Психология общения', 'Менеджмент', 'Методика преподавания',
            'Практика исполнительская', 'Консультация к экзамену',
        ];

        return collect($items)->map(function (string $name, int $index): Subject {
            $subject = $this->writeRow(
                Subject::query(),
                ['code' => $index === 0 ? 'MUS-101' : sprintf('DEMO-SUB-%02d', $index + 1)],
                [
                    'name' => $name,
                    'department' => $index % 3 === 0 ? 'Музыкальное отделение' : 'Общеобразовательное отделение',
                    'description' => 'Демонстрационная дисциплина.',
                ]
            );

            $this->subjectOrdinals[$subject->id] = $index;

            return $subject;
        })->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Classroom>
     */
    private function seedClassrooms()
    {
        return collect(range(1, 25))->map(fn (int $index): Classroom => $this->writeRow(
            Classroom::query(),
            ['number' => (string) (100 + $index), 'building' => 'Демо-корпус'],
            [
                'floor' => (int) ceil($index / 8),
                'capacity' => 18 + ($index % 12),
                'type' => $index % 5 === 0 ? 'Зал' : 'Учебная аудитория',
                'description' => 'Демонстрационная аудитория.',
            ]
        ))->values();
    }

    private function seedHrReferences(): array
    {
        $departments = collect([
            ['DEMO-MUSIC', 'Музыкальное отделение'],
            ['DEMO-GENERAL', 'Общеобразовательное отделение'],
        ])->mapWithKeys(fn (array $item): array => [
            $item[1] => $this->writeRow(
                Department::query(),
                ['code' => $item[0]],
                ['name' => $item[1], 'type' => 'academic', 'is_active' => true]
            ),
        ]);

        $positions = collect([
            ['DEMO-TEACHER', 'Преподаватель', true],
            ['DEMO-DEPARTMENT-HEAD', 'Заведующий отделением', true],
        ])->mapWithKeys(fn (array $item): array => [
            $item[1] => $this->writeRow(
                Position::query(),
                ['code' => $item[0]],
                ['name' => $item[1], 'category' => 'teaching', 'is_teaching_position' => $item[2], 'is_active' => true]
            ),
        ]);

        return ['departments' => $departments, 'positions' => $positions];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Teacher>
     */
    private function seedTeachers(Role $teacherRole, string $demoPassword, array $hr)
    {
        return collect(range(1, self::TEACHER_COUNT))->map(function (int $index) use ($teacherRole, $demoPassword, $hr): Teacher {
            $email = $index === 1 ? 'teacher@local' : sprintf('teacher.demo.%03d@%s', $index, self::DEMO_DOMAIN);
            // В колледже искусств преподавательский состав преимущественно
            // женский, и набор, где ровно половина мужчин, выглядит неправдой.
            ['last_name' => $lastName, 'first_name' => $firstName, 'middle_name' => $middleName] = $this->names->next($index % 3 === 0 ? 'male' : 'female');
            $positionName = $index % 6 === 0 ? 'Заведующий отделением' : 'Преподаватель';
            $departmentName = $index % 4 === 0 ? 'Общеобразовательное отделение' : 'Музыкальное отделение';
            $person = $this->seedPerson($lastName, $firstName, $middleName, null, $email, sprintf('+7900%07d', 1000000 + $index));

            $user = $index === 1
                ? $this->writeRow(
                    User::query(),
                    ['email' => $email],
                    ['role_id' => $teacherRole->id, 'person_id' => $person->id, 'person_type' => 'person', 'name' => "{$lastName} {$firstName} {$middleName}", 'password' => Hash::make($demoPassword), 'is_active' => true]
                )
                : null;

            $teacher = $this->writeRow(
                Teacher::query(),
                ['email' => $email],
                [
                    'person_id' => $person->id,
                    'user_id' => $user?->id,
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'phone' => sprintf('+7900%07d', 1000000 + $index),
                    'position' => $positionName,
                    'department' => $departmentName,
                    'is_active' => true,
                ]
            );

            $this->seedEmployeeForTeacher($person, $index, $departmentName, $positionName, $hr);
            $this->teacherOrdinals[$teacher->id] = $index;

            return $teacher;
        })->values();
    }

    /**
     * @param \Illuminate\Support\Collection<int, EducationProgram> $programs
     * @param \Illuminate\Support\Collection<int, Teacher> $teachers
     * @return \Illuminate\Support\Collection<int, Group>
     */
    private function seedGroups($programs, $teachers)
    {
        return collect(range(1, self::GROUP_COUNT))->map(function (int $index) use ($programs, $teachers): Group {
            $course = (($index - 1) % 4) + 1;
            $program = $programs[($index - 1) % $programs->count()];

            return $this->writeRow(
                Group::query(),
                ['name' => sprintf('ДЕМО-%02d%d', $index, $course)],
                [
                    'specialty' => $program->specialty?->name ?? 'Демонстрационная специальность',
                    'education_program_id' => $program->id,
                    'course' => $course,
                    'year_start' => 2027 - $course,
                    'curator_id' => $teachers[($index - 1) % $teachers->count()]->id,
                ]
            );
        })->values();
    }

    /**
     * @param \Illuminate\Support\Collection<int, Group> $groups
     * @return \Illuminate\Support\Collection<int, Student>
     */
    private function seedStudents(Role $studentRole, string $demoPassword, $groups)
    {
        return collect(range(1, self::STUDENT_COUNT))->map(function (int $index) use ($studentRole, $demoPassword, $groups): Student {
            $email = $index === 1 ? 'student@local' : sprintf('student.demo.%03d@%s', $index, self::DEMO_DOMAIN);
            ['last_name' => $lastName, 'first_name' => $firstName, 'middle_name' => $middleName] = $this->names->next();
            $group = $groups[($index - 1) % $groups->count()];
            // Ровесники в группе, но не одного дня рождения: возраст пляшет
            // на пару лет, как в настоящем наборе.
            $birthYear = 2027 - $group->course - 16 - ($index % 3);
            $birthDate = Carbon::create($birthYear, (($index * 7) % 12) + 1, (($index * 13) % 28) + 1)->toDateString();
            $person = $this->seedPerson($lastName, $firstName, $middleName, $birthDate, $email, sprintf('+7910%07d', 1000000 + $index));

            $user = $index === 1
                ? $this->writeRow(
                    User::query(),
                    ['email' => $email],
                    ['role_id' => $studentRole->id, 'person_id' => $person->id, 'person_type' => 'person', 'name' => "{$lastName} {$firstName} {$middleName}", 'password' => Hash::make($demoPassword), 'is_active' => true]
                )
                : null;

            $student = $this->writeRow(
                Student::query(),
                ['email' => $email],
                [
                    'person_id' => $person->id,
                    'user_id' => $user?->id,
                    'group_id' => $group->id,
                    'course' => $group->course,
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'birth_date' => $birthDate,
                    'phone' => sprintf('+7910%07d', 1000000 + $index),
                    'status' => $index % 37 === 0 ? 'academic_leave' : 'active',
                    'enrollment_date' => Carbon::create($group->year_start, 9, 1)->toDateString(),
                    'education_form' => 'Очная',
                    'funding_form' => $index % 5 === 0 ? 'Договор' : 'Бюджет',
                ]
            );

            $this->studentOrdinals[$student->id] = $index;

            return $student;
        })->values();
    }

    private function seedTeacherSubjects($teachers, $subjects): void
    {
        foreach ($teachers as $index => $teacher) {
            $teacher->subjects()->syncWithoutDetaching([
                $subjects[$index % $subjects->count()]->id,
                $subjects[($index + 5) % $subjects->count()]->id,
            ]);
        }
    }

    /**
     * Расписание строится на две недели назад и на текущую неделю: отчёты,
     * журнал и проходная без прошлого показывают пустые экраны, а показать
     * нужно работу, а не форму.
     */
    private function seedWeeklySchedule($groups, $teachers, $subjects, $classrooms)
    {
        $startDate = Carbon::today()->startOfWeek()->subDays(self::HISTORY_DAYS);
        $times = [['08:30', '10:00'], ['10:10', '11:40'], ['12:10', '13:40'], ['13:50', '15:20']];
        $lessons = collect();
        $days = range(0, self::HISTORY_DAYS + 4);

        foreach ($groups as $groupIndex => $group) {
            foreach ($days as $dayOffset) {
                $date = $startDate->copy()->addDays($dayOffset);

                if ($date->isWeekend()) {
                    continue;
                }

                // Первая пара не у всех и не каждый день: расписание, где у всех
                // тридцати групп занятия с 8:30 до 15:20 ровно пять дней в неделю,
                // сразу выдаёт генератор.
                $slots = ($groupIndex + $dayOffset) % 5 === 0 ? array_slice($times, 1) : $times;

                foreach ($slots as $slotIndex => [$startsAt, $endsAt]) {
                    $teacher = $teachers[($groupIndex * 3 + $slotIndex * 7 + $dayOffset) % $teachers->count()];
                    $subject = $subjects[($groupIndex + $slotIndex + intdiv($dayOffset, 5)) % $subjects->count()];
                    $classroom = $classrooms[($groupIndex + $slotIndex + $dayOffset) % $classrooms->count()];
                    $lessons->push($this->writeRow(
                        ScheduleLesson::query(),
                        [
                            'group_id' => $group->id,
                            'teacher_id' => $teacher->id,
                            'subject_id' => $subject->id,
                            'lesson_date' => $date->toDateString(),
                            'starts_at' => $startsAt,
                        ],
                        [
                            'classroom_id' => $classroom->id,
                            'ends_at' => $endsAt,
                            'lesson_type' => $slotIndex === 3 ? 'practice' : 'lesson',
                            'topic' => $this->lessonTopic($subject->name, $dayOffset + $slotIndex),
                        ]
                    ));
                }
            }
        }

        return $lessons;
    }

    /**
     * Журнал заполняется только по прошедшим занятиям и по профилю студента.
     *
     * Прежний набор ставил оценки по остатку от деления: у всех выходила
     * одинаковая ровная успеваемость, отличников и отстающих не было, и
     * отчёт по группе показывал прямую линию. Здесь у каждого студента свой
     * уровень, а у части — провал по одной дисциплине: именно так выглядят
     * настоящие ведомости, и именно на них видно, зачем нужны отчёты.
     */
    /** @return array{attendance: array<int, array<string, mixed>>, grades: array<int, array<string, mixed>>} */
    private function seedJournalSamples($lessons, $students): array
    {
        $studentsByGroup = $students->groupBy('group_id');
        $past = $this->startedLessons($lessons);

        // Снимается всё расписание, а наполняются только начавшиеся пары.
        // Удалять по тому же списку, что и наполнять, недостаточно: отметки за
        // сегодня, поставленные прошлым наполнением, пережили бы новое — а пара
        // ещё не началась, и на экране это выглядело бы как «отмечен, но в
        // здание не входил». Ровно так стенд и выглядел: 583 расхождения из
        // ниоткуда.
        $allLessonIds = $lessons->pluck('id');
        Attendance::query()->whereIn('schedule_lesson_id', $allLessonIds)->delete();
        Grade::query()->whereIn('schedule_lesson_id', $allLessonIds)->delete();

        $now = now();
        $attendanceRows = [];
        $gradeRows = [];

        foreach ($past as $lesson) {
            foreach (($studentsByGroup[$lesson->group_id] ?? collect()) as $student) {
                $profile = $this->studentProfile($student->id);
                // Провальная дисциплина своя у каждого пятого: ровный студент,
                // просевший на одном предмете, — обычная картина ведомости.
                // И студент, и дисциплина берутся по порядковому номеру в наборе:
                // от идентификатора строки зависеть нельзя. Через `subject_id`
                // эта зависимость оставалась незамеченной — а она сдвигала
                // раскладку оценок и весь дальнейший поток случайных значений,
                // и проверка снова мигала в полном прогоне.
                $ordinal = $this->ordinal($this->studentOrdinals, $student->id);
                $subjectOrdinal = $this->ordinal($this->subjectOrdinals, $lesson->subject_id);
                $struggles = $ordinal % 5 === 0 && $subjectOrdinal % 4 === $ordinal % 4;
                $status = $this->attendanceStatus($struggles ? 'weak' : $profile);

                $attendanceRows[] = [
                    'schedule_lesson_id' => $lesson->id,
                    'student_id' => $student->id,
                    'status' => $status,
                    'comment' => $status === 'late' ? 'Опоздание '.mt_rand(5, 25).' минут.' : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($status === 'absent' || mt_rand(1, 100) > 45) {
                    continue;
                }

                $gradeRows[] = [
                    'schedule_lesson_id' => $lesson->id,
                    'student_id' => $student->id,
                    'grade' => (string) $this->gradeFor($struggles ? 'weak' : $profile),
                    'grade_type' => 'classwork',
                    'comment' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Вставка пачками: построчный updateOrCreate на десятках тысяч записей
        // превращал наполнение стенда в многоминутное ожидание.
        foreach (array_chunk($attendanceRows, 1000) as $chunk) {
            Attendance::query()->insert($chunk);
        }

        foreach (array_chunk($gradeRows, 1000) as $chunk) {
            Grade::query()->insert($chunk);
        }

        // Те же отметки уходят и в движок журнала: два экрана об одном занятии
        // обязаны показывать одно и то же.
        return ['attendance' => $attendanceRows, 'grades' => $gradeRows];
    }

    /**
     * Журнал преподавателя: занятия, отметки и оценки в движке журнала.
     *
     * Раньше набор наполнял только старые таблицы, привязанные к расписанию, а
     * экран «Журнал» читает движок — и открывался пустым при 1710 занятиях в
     * расписании. Записи движка выводятся из уже разложенных отметок, а не
     * бросаются заново: посещаемость в журнале и в отчётах должна совпадать.
     *
     * Прошедшие занятия закрыты, последний день оставлен открытым — так и
     * выглядит журнал в работе. Занятия преподавателя с учётной записью
     * подписаны: без подписанных занятий не видно ни запрета на правку, ни
     * заявок на переоткрытие.
     *
     * @param array{attendance: array<int, array<string, mixed>>, grades: array<int, array<string, mixed>>} $marks
     */
    private function seedJournalEngine($lessons, array $marks): void
    {
        $today = Carbon::today();
        $past = $this->startedLessons($lessons);

        // Журнал прошлого наполнения снимается по всему расписанию, а не по
        // начавшимся парам: иначе запись за сегодня, сделанная вчерашним
        // наполнением, переживёт новое и будет утверждать, что пара уже прошла.
        $lessonIds = $past->pluck('id');
        JournalLesson::query()->whereIn('legacy_schedule_lesson_id', $lessons->pluck('id'))->delete();

        if ($past->isEmpty()) {
            return;
        }

        $signingTeacherId = Teacher::query()->whereNotNull('user_id')->value('id');
        $signerUserId = Teacher::query()->whereNotNull('user_id')->value('user_id');
        $now = now();
        $rows = [];

        foreach ($past as $lesson) {
            $isToday = (string) $lesson->lesson_date->toDateString() === $today->toDateString();
            $signed = ! $isToday && (int) $lesson->teacher_id === (int) $signingTeacherId;
            $status = match (true) {
                $isToday => JournalLesson::STATUS_IN_PROGRESS,
                $signed => JournalLesson::STATUS_SIGNED,
                default => JournalLesson::STATUS_COMPLETED,
            };
            $openedAt = $lesson->lesson_date->copy()->setTimeFromTimeString($this->timeString($lesson->starts_at));

            $rows[] = [
                'legacy_schedule_lesson_id' => $lesson->id,
                'group_id' => $lesson->group_id,
                'subject_id' => $lesson->subject_id,
                'teacher_id' => $lesson->teacher_id,
                'lesson_date' => $lesson->lesson_date->toDateString(),
                'starts_at' => $this->timeString($lesson->starts_at),
                'ends_at' => $this->timeString($lesson->ends_at),
                'topic' => $lesson->topic,
                'status' => $status,
                'opened_at' => $openedAt,
                'completed_at' => $isToday ? null : $openedAt->copy()->addMinutes(90),
                'signed_at' => $signed ? $openedAt->copy()->addHours(3) : null,
                'signed_by' => $signed ? $signerUserId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            JournalLesson::query()->insert($chunk);
        }

        $journalIds = JournalLesson::query()
            ->whereIn('legacy_schedule_lesson_id', $lessonIds)
            ->pluck('id', 'legacy_schedule_lesson_id');

        // Строки уходят в базу пачками по ходу, а не копятся целиком: журнал на
        // две недели это двадцать пять тысяч отметок и десять тысяч оценок, и
        // вторая их копия в памяти рядом с первой упирала прогон в потолок —
        // запрос к стенду отвечал пятисотой там, где раньше проходил.
        $attendanceRows = [];
        foreach ($marks['attendance'] as $mark) {
            $journalId = $journalIds[$mark['schedule_lesson_id']] ?? null;
            if ($journalId === null) {
                continue;
            }

            if (count($attendanceRows) >= 1000) {
                JournalAttendance::query()->insert($attendanceRows);
                $attendanceRows = [];
            }

            $attendanceRows[] = [
                'journal_lesson_id' => $journalId,
                'student_id' => $mark['student_id'],
                'status' => $mark['status'],
                'minutes_late' => $mark['status'] === 'late' ? 10 : null,
                'comment' => $mark['comment'],
                'source' => 'teacher',
                'marked_by' => $signerUserId,
                'marked_at' => $now,
            ];
        }

        if ($attendanceRows !== []) {
            JournalAttendance::query()->insert($attendanceRows);
            $attendanceRows = [];
        }

        $gradeRows = [];
        foreach ($marks['grades'] as $mark) {
            $journalId = $journalIds[$mark['schedule_lesson_id']] ?? null;
            if ($journalId === null) {
                continue;
            }

            if (count($gradeRows) >= 1000) {
                JournalGrade::query()->insert($gradeRows);
                $gradeRows = [];
            }

            $gradeRows[] = [
                'journal_lesson_id' => $journalId,
                'student_id' => $mark['student_id'],
                'value' => $mark['grade'],
                'weight' => 1,
                'marked_by' => $signerUserId,
                'marked_at' => $now,
            ];
        }

        if ($gradeRows !== []) {
            JournalGrade::query()->insert($gradeRows);
        }
    }

    /**
     * Движок расписания: занятия в его собственной таблице.
     *
     * Набор писал только в `schedule_lessons`, а покрытие часов и конфликты
     * читают `schedule_entries` — экран расписания показывал пары, а блоки
     * «Покрытие» и «Конфликты» рядом с ними оставались пустыми при полутора
     * тысячах занятий.
     *
     * Каждая запись привязывается к строке нагрузки той же группы и той же
     * дисциплины: именно по этой связи движок считает, сколько часов уже
     * поставлено против запланированных. Без привязки покрытие показывало бы
     * ноль поставленных часов у всех — то есть ровно то же самое пустое место.
     *
     * @param \Illuminate\Support\Collection<int, ScheduleLesson> $lessons
     */
    private function seedScheduleEntries($lessons): void
    {
        if ($lessons->isEmpty()) {
            return;
        }

        $academicYear = (string) (SettingService::value('academic', 'current_academic_year', '') ?: '2026/2027');

        ScheduleEntry::query()->whereIn('group_id', $lessons->pluck('group_id')->unique())->delete();

        // Строка нагрузки на пару «группа + дисциплина». Их может быть
        // несколько — дисциплина идёт в разных семестрах, — и берётся та, у
        // которой есть преподаватель: покрытие без преподавателя ни о чём.
        $items = TeachingLoadItem::query()
            ->orderByRaw('teacher_id is null')
            ->orderBy('id')
            ->get(['id', 'group_id', 'subject_id', 'semester']);
        $itemByPair = [];
        foreach ($items as $item) {
            $itemByPair[$item->group_id.':'.$item->subject_id] ??= $item;
        }

        $slots = ['08:30' => 1, '10:10' => 2, '12:10' => 3, '13:50' => 4];
        $now = now();
        $rows = [];

        foreach ($lessons as $lesson) {
            $startsAt = $this->timeString($lesson->starts_at);
            $item = $itemByPair[$lesson->group_id.':'.$lesson->subject_id] ?? null;

            if (count($rows) >= 1000) {
                ScheduleEntry::query()->insert($rows);
                $rows = [];
            }

            $rows[] = [
                'academic_year' => $academicYear,
                'semester' => $item?->semester ?? 1,
                'date' => $lesson->lesson_date->toDateString(),
                'day_of_week' => $lesson->lesson_date->dayOfWeekIso,
                'lesson_number' => $slots[$startsAt] ?? 1,
                'starts_at' => $startsAt,
                'ends_at' => $this->timeString($lesson->ends_at),
                'group_id' => $lesson->group_id,
                'subject_id' => $lesson->subject_id,
                'teacher_id' => $lesson->teacher_id,
                'classroom_id' => $lesson->classroom_id,
                'teaching_load_item_id' => $item?->id,
                'status' => 'scheduled',
                'source' => 'demo_data',
                'is_replacement' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            ScheduleEntry::query()->insert($rows);
        }
    }

    /**
     * Выпускники прошлого года с дипломами.
     *
     * Раздел открывался пустым: выпускников набор не создавал вовсе, поэтому
     * не на чем было увидеть ни реестр, ни выгрузку, ни приложение к диплому —
     * то самое, которое обратная загрузка недавно научилась не терять.
     *
     * Выпускники берутся из групп четвёртого курса и выпускаются прошлым
     * годом: человек, выпустившийся в этом году, ещё числился бы студентом, и
     * реестр противоречил бы контингенту.
     *
     * @param \Illuminate\Support\Collection<int, Group> $groups
     * @param \Illuminate\Support\Collection<int, Student> $students
     */
    private function seedGraduates($groups, $students): void
    {
        $finalCourse = $groups->where('course', 4);

        if ($finalCourse->isEmpty()) {
            return;
        }

        $graduationYear = (int) Carbon::today()->year - 1;
        $byGroup = $students->groupBy('group_id');
        $programs = Group::query()->whereIn('id', $finalCourse->pluck('id'))->with('educationProgram.specialty')->get()->keyBy('id');
        $index = 0;

        foreach ($finalCourse as $group) {
            $program = $programs[$group->id]?->educationProgram ?? null;

            // По десять человек с группы: реестр должен быть похож на выпуск, а
            // не на весь контингент, и оставаться обозримым на экране.
            foreach (($byGroup[$group->id] ?? collect())->take(10) as $student) {
                $index++;
                $graduate = $this->writeRow(
                    Graduate::query(),
                    ['student_id' => $student->id],
                    [
                        'person_id' => $student->person_id,
                        'group_id' => $group->id,
                        'education_program_id' => $program?->id,
                        'specialty_id' => $program?->specialty?->id,
                        'graduation_year' => $graduationYear,
                        'qualification' => $program?->specialty?->qualification,
                        // Каждый десятый ещё без диплома: реестр, где у всех всё
                        // выдано, не показывает работы, ради которой он и нужен.
                        'status' => $index % 10 === 0 ? 'ready' : 'issued',
                        'note' => 'Демонстрационная запись выпускника.',
                    ]
                );

                if ($index % 10 === 0) {
                    $graduate->diploma()->delete();

                    continue;
                }

                $diploma = $this->writeRow(
                    $graduate->diploma(),
                    ['graduate_id' => $graduate->id],
                    [
                        'series' => 'СК',
                        'number' => sprintf('%06d', 100000 + $index),
                        'registration_number' => sprintf('%d-%03d', $graduationYear % 100, $index),
                        'issue_date' => Carbon::create($graduationYear, 6, 30)->toDateString(),
                        'qualification' => $graduate->qualification,
                        'gia_decision' => sprintf('Протокол ГИА № %d от 25.06.%d', ($index % 7) + 1, $graduationYear),
                        'status' => 'issued',
                    ]
                );

                // Приложение не у всех: без этого не видно ни его выгрузки, ни
                // того, что обратная загрузка его больше не теряет.
                if ($index % 3 !== 0) {
                    $this->writeRow(
                        $diploma->supplement(),
                        ['diploma_id' => $diploma->id],
                        [
                            'series' => 'ПР',
                            'number' => sprintf('%06d', 500000 + $index),
                            'issue_date' => Carbon::create($graduationYear, 6, 30)->toDateString(),
                            'status' => 'issued',
                        ]
                    );
                }
            }
        }
    }

    /**
     * Занятия, которые уже начались: только по ним есть отметки и журнал.
     *
     * Сравнение вынесено сюда, потому что на нём легко ошибиться, и ошибка
     * была: `lesson_date` приведён к дате и в сравнении со строкой `Y-m-d`
     * превращается в `Y-m-d H:i:s`, то есть оказывается больше её. Сегодняшние
     * занятия из-за этого молча выпадали, и сегодняшнего дня в журнале и в
     * оценках не было вовсе.
     *
     * @param \Illuminate\Support\Collection<int, ScheduleLesson> $lessons
     * @return \Illuminate\Support\Collection<int, ScheduleLesson>
     */
    private function startedLessons($lessons)
    {
        $now = now();

        return $lessons->filter(function (ScheduleLesson $lesson) use ($now): bool {
            $startedAt = $lesson->lesson_date->copy()->setTimeFromTimeString($this->timeString($lesson->starts_at));

            return $startedAt->lessThanOrEqualTo($now);
        });
    }

    /** Время занятия строкой «ЧЧ:ММ»: в модели это может быть и строка, и дата. */
    private function timeString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }

    /**
     * Нагрузка преподавателей: строится тем же генератором, что и в портале.
     *
     * Своей раскладки здесь нет намеренно — иначе демонстрация показывала бы
     * не то, что делает кнопка «Сформировать из учебного плана». Часть строк
     * оставлена без преподавателя: покрытие часов, показывающее сплошные сто
     * процентов, не показывает ничего.
     *
     * @param \Illuminate\Support\Collection<int, Group> $groups
     * @param \Illuminate\Support\Collection<int, Teacher> $teachers
     */
    private function seedTeachingLoads($groups, $teachers): void
    {
        $academicYear = (string) (SettingService::value('academic', 'current_academic_year', '') ?: '2026/2027');
        $generator = app(TeachingLoadGenerationService::class);

        // Нагрузка прошлого наполнения снимается целиком, а не обновляется.
        // Дисциплины плана при каждом запуске раскладываются заново и получают
        // новые идентификаторы, поэтому генератор старых строк не узнаёт и
        // пытается создать вторые такие же — на стенде это падало нарушением
        // уникальности. В тестах база каждый раз пустая, и увидеть это можно
        // было только на втором запуске набора подряд.
        $staleLoadIds = TeachingLoad::query()->whereIn('group_id', $groups->pluck('id'))->pluck('id');
        TeachingLoadItem::query()->whereIn('teaching_load_id', $staleLoadIds)->delete();
        TeachingLoad::query()->whereIn('id', $staleLoadIds)->delete();

        foreach ($groups as $groupIndex => $group) {
            $generator->apply($group->id, $academicYear);

            $items = TeachingLoadItem::query()
                ->where('group_id', $group->id)
                ->whereNull('teacher_id')
                ->orderBy('id')
                ->get(['id', 'planned_hours', 'hours_total']);

            foreach ($items as $itemIndex => $item) {
                // Каждая седьмая строка остаётся нераспределённой: именно их
                // ищет методист на экране покрытия часов.
                if (($groupIndex + $itemIndex) % 7 === 0) {
                    continue;
                }

                $planned = (int) ($item->planned_hours ?: $item->hours_total);
                $teacher = $teachers[($groupIndex * 5 + $itemIndex) % $teachers->count()];

                TeachingLoadItem::query()->whereKey($item->id)->update([
                    'teacher_id' => $teacher->id,
                    'assigned_hours' => $planned,
                    'unassigned_hours' => 0,
                    'overassigned_hours' => 0,
                    'assignment_status' => 'assigned',
                ]);
            }
        }
    }

    /**
     * Учебные планы: по одному на образовательную программу.
     *
     * План нужен не сам по себе, а как основание нагрузки: генератор
     * (`TeachingLoadGenerationService`) строит её из дисциплин плана по
     * семестрам и без плана возвращает «У группы не назначен учебный план».
     *
     * Дисциплины пишутся в две таблицы. `curriculum_subjects` читает движок —
     * из неё берётся нагрузка; `curriculum_items` читает выгрузка планов и
     * старый экран. Это те же строки, а не два разных плана: разойдись они —
     * и выгрузка показывала бы одно, а нагрузка считалась бы по другому.
     *
     * @param \Illuminate\Support\Collection<int, EducationProgram> $programs
     * @param \Illuminate\Support\Collection<int, Subject> $subjects
     * @return \Illuminate\Support\Collection<int, Curriculum>
     */
    private function seedCurricula($programs, $subjects)
    {
        $controlForms = ['Экзамен', 'Зачет', 'Дифференцированный зачет', 'Контрольная работа'];

        return $programs->map(function (EducationProgram $program, int $index) use ($subjects, $controlForms): Curriculum {
            $curriculum = $this->writeRow(
                Curriculum::query(),
                ['code' => sprintf('УП-ДЕМО-%02d', $index + 1)],
                [
                    'education_program_id' => $program->id,
                    'name' => 'Учебный план: '.$program->name,
                    'year_start' => $program->year_start,
                    'status' => 'active',
                    'description' => 'Демонстрационный учебный план без реальных данных.',
                ]
            );

            $engineRows = [];
            $legacyRows = [];
            $now = now();
            $semesters = range(1, 8);

            foreach ($semesters as $semester) {
                $course = intdiv($semester + 1, 2);

                foreach (range(0, 5) as $slot) {
                    $subject = $subjects[($index * 7 + $semester * 3 + $slot) % $subjects->count()];
                    // Часы кратны 18: столько же длится семестровый курс в
                    // расписании, и суммы в нагрузке выглядят как настоящие.
                    $hours = 36 + (($index + $semester + $slot) % 4) * 18;

                    $engineRows[] = [
                        'curriculum_id' => $curriculum->id,
                        'semester' => $semester,
                        'subject_id' => $subject->id,
                        'lecture_hours' => intdiv($hours, 3),
                        'practice_hours' => $hours - intdiv($hours, 3),
                        'laboratory_hours' => 0,
                        'independent_hours' => intdiv($hours, 6),
                        'total_hours' => $hours,
                        'control_type' => $controlForms[($semester + $slot) % count($controlForms)],
                        'sequence' => $slot,
                        'is_optional' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $legacyRows[] = [
                        'curriculum_id' => $curriculum->id,
                        'subject_id' => $subject->id,
                        'course' => $course,
                        'semester' => $semester,
                        'hours_total' => $hours,
                        'control_form' => $controlForms[($semester + $slot) % count($controlForms)],
                        'sort_order' => $slot,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Пачками, а не построчным updateOrCreate: на двенадцати планах это
            // разница между секундой и минутой, и набор уже наступал на эти грабли.
            CurriculumSubject::query()->where('curriculum_id', $curriculum->id)->delete();
            CurriculumItem::query()->where('curriculum_id', $curriculum->id)->delete();
            foreach (array_chunk($engineRows, 500) as $chunk) {
                CurriculumSubject::query()->insert($chunk);
            }
            foreach (array_chunk($legacyRows, 500) as $chunk) {
                CurriculumItem::query()->insert($chunk);
            }

            return $curriculum;
        })->values();
    }

    /**
     * @param \Illuminate\Support\Collection<int, Group> $groups
     * @param \Illuminate\Support\Collection<int, Curriculum> $curricula
     */
    private function attachCurriculaToGroups($groups, $curricula): void
    {
        $byProgram = $curricula->keyBy('education_program_id');

        foreach ($groups as $group) {
            $curriculum = $byProgram->get($group->education_program_id);

            if ($curriculum && (int) $group->curriculum_id !== (int) $curriculum->id) {
                $group->forceFill(['curriculum_id' => $curriculum->id])->save();
            }
        }
    }

    /**
     * Уровень студента устойчив между запусками: он выводится из порядкового
     * номера в наборе, а не бросается заново. Иначе отличник при следующем
     * наполнении стенда становится отстающим, и сравнить два прогона нельзя.
     *
     * Порядковый номер, а не идентификатор строки: идентификатор — это не
     * свойство человека, а место в очереди на вставку, и зависит он от того,
     * что лежало в базе раньше. В прогоне тестов последовательности не
     * откатываются вместе с транзакцией, демо-набор получал идентификаторы
     * с произвольного места, и состав отличников и отстающих менялся от
     * прогона к прогону. Отсюда и брались случайные падения проверки на
     * опоздавших: в одном окне идентификаторов их шесть, в другом ноль.
     */
    private function studentProfile(int $studentId): string
    {
        $bucket = ($this->ordinal($this->studentOrdinals, $studentId) * 7919) % 100;

        return match (true) {
            $bucket < 12 => 'excellent',
            $bucket < 45 => 'good',
            $bucket < 82 => 'average',
            default => 'weak',
        };
    }

    /**
     * Преподаватели приходят раньше студентов, но не поголовно: у кого-то не
     * вышел автобус. Без этого сводка «Посещаемость» показывала ноль
     * опоздавших — экран есть, а показывать на нём нечего.
     */
    private function teacherProfile(int $teacherId): string
    {
        $bucket = ($this->ordinal($this->teacherOrdinals, $teacherId) * 6421) % 100;

        return match (true) {
            $bucket < 70 => 'excellent',
            $bucket < 92 => 'good',
            default => 'weak',
        };
    }

    /**
     * Порядковый номер строки в наборе. Своего номера нет только у записей,
     * заведённых не этим набором, — для них остаётся идентификатор.
     *
     * @param array<int, int> $map
     */
    private function ordinal(array $map, int $id): int
    {
        return $map[$id] ?? $id;
    }

    private function attendanceStatus(string $profile): string
    {
        $roll = mt_rand(1, 100);

        return match ($profile) {
            'excellent' => $roll <= 96 ? 'present' : ($roll <= 99 ? 'late' : 'absent'),
            'good' => $roll <= 90 ? 'present' : ($roll <= 97 ? 'late' : 'absent'),
            'average' => $roll <= 82 ? 'present' : ($roll <= 92 ? 'late' : 'absent'),
            default => $roll <= 65 ? 'present' : ($roll <= 80 ? 'late' : 'absent'),
        };
    }

    private function gradeFor(string $profile): int
    {
        $roll = mt_rand(1, 100);

        return match ($profile) {
            'excellent' => $roll <= 70 ? 5 : ($roll <= 98 ? 4 : 3),
            'good' => $roll <= 30 ? 5 : ($roll <= 80 ? 4 : ($roll <= 98 ? 3 : 2)),
            'average' => $roll <= 10 ? 5 : ($roll <= 50 ? 4 : ($roll <= 92 ? 3 : 2)),
            default => $roll <= 2 ? 5 : ($roll <= 20 ? 4 : ($roll <= 70 ? 3 : 2)),
        };
    }

    private function lessonTopic(string $subjectName, int $index): string
    {
        $topics = ['Введение в тему', 'Разбор материала', 'Практическая работа', 'Контрольная работа', 'Повторение', 'Подготовка к зачёту'];

        return $topics[$index % count($topics)].': '.$subjectName;
    }

    private function seedDigitalIdentities($students, $teachers): void
    {
        foreach ($students as $student) {
            $this->writeRow(
                DigitalIdentity::query(),
                ['entity_type' => DigitalIdentity::ENTITY_STUDENT, 'entity_id' => $student->id],
                ['person_id' => $student->person_id, 'token' => (string) Str::uuid(), 'status' => DigitalIdentity::STATUS_ACTIVE, 'issued_at' => now(), 'expires_at' => null, 'revoked_at' => null]
            );
        }

        foreach ($teachers as $teacher) {
            $this->writeRow(
                DigitalIdentity::query(),
                ['entity_type' => DigitalIdentity::ENTITY_TEACHER, 'entity_id' => $teacher->id],
                ['person_id' => $teacher->person_id, 'token' => (string) Str::uuid(), 'status' => DigitalIdentity::STATUS_ACTIVE, 'issued_at' => now(), 'expires_at' => null, 'revoked_at' => null]
            );
        }
    }

    /**
     * Проходы за две недели, а не один шаблон на всех.
     *
     * Прежний набор ставил всем один и тот же вход в 8:30 с поправкой по
     * остатку от деления и только за сегодня. Отчёт проходной на таких данных
     * показывает ровный частокол: не видно ни опоздавших, ни тех, кто вышел на
     * обед и вернулся, ни отсутствующих, ни выходных. Здесь у человека есть
     * привычка приходить вовремя или опаздывать, часть людей в конкретный день
     * не приходит вовсе, по выходным здание почти пустое, а часть пропусков
     * с первого раза не читается и человек прикладывает его повторно.
     */
    private function seedAccessEvents($students, $teachers, $lessons): void
    {
        $window = $this->lessonWindows($lessons);
        $groupByStudent = $students->pluck('group_id', 'id');
        $now = now();

        $identities = DigitalIdentity::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('entity_type', DigitalIdentity::ENTITY_STUDENT)->whereIn('entity_id', $students->pluck('id')))
                ->orWhere(fn ($q) => $q->where('entity_type', DigitalIdentity::ENTITY_TEACHER)->whereIn('entity_id', $teachers->pluck('id'))))
            // Порядок задан явно: без него набор случайных значений раскладывался
            // бы по людям так, как база решит вернуть строки.
            ->orderBy('id')
            ->get(['id', 'entity_type', 'entity_id']);

        if ($identities->isEmpty()) {
            return;
        }

        AccessEvent::query()
            ->whereIn('digital_identity_id', $identities->pluck('id'))
            ->where('device_name', 'Демо-турникет')
            ->delete();

        // Проходы раскладываются по настоящим точкам справочника, а не по
        // выдуманным названиям. Пока набор писал «Главный вход» строкой и не
        // ставил связь, все демо-события попадали в группу «точка вне
        // справочника», и отчёт «Кто в здании» на стенде выглядел сломанным —
        // хотя сломан был не отчёт. Точки заводит миграция
        // `2026_08_15_000001`, своих набор не создаёт.
        $points = AccessPoint::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        if ($points->isEmpty()) {
            // Молча пропустить нельзя: пропадёт целый раздел стенда, и искать
            // причину будут в проходной, а не в пустом справочнике.
            $this->command?->warn('Справочник точек прохода пуст — события проходной не наполнены.');

            return;
        }

        $rows = [];

        foreach (range(self::HISTORY_DAYS, 0) as $daysAgo) {
            // События уходят в базу днём, а не все четырнадцать разом: копить
            // тридцать тысяч строк в памяти незачем.
            if ($rows !== []) {
                AccessEvent::query()->insert($rows);
                $rows = [];
            }

            $day = Carbon::today()->subDays($daysAgo);
            $weekend = $day->isWeekend();

            foreach ($identities as $identity) {
                $teacher = $identity->entity_type === DigitalIdentity::ENTITY_TEACHER;
                $profile = $teacher ? $this->teacherProfile($identity->entity_id) : $this->studentProfile($identity->entity_id);

                // По выходным в здании репетиции: приходят единицы.
                if (mt_rand(1, 100) > ($weekend ? 8 : ($teacher ? 94 : 88))) {
                    continue;
                }

                $point = $points[mt_rand(0, $points->count() - 1)];
                // Человек приходит к своей первой паре, а не к общим 8:30.
                // Пока приход был привязан к фиксированному времени, опоздать
                // мог только тот, у кого первая пара ровно в 8:30, — таких на
                // день двое, и «опоздал» на экране почти никогда не появлялся.
                // Когда первой пары нет, остаётся прежнее начало дня.
                $key = $teacher
                    ? 'teacher:'.$identity->entity_id
                    : 'group:'.($groupByStudent[$identity->entity_id] ?? 0);
                $startsAt = $window[$day->toDateString().'|'.$key] ?? '08:30';
                $base = $day->copy()->setTimeFromTimeString($startsAt);
                $shift = match ($profile) {
                    'excellent' => mt_rand(-40, -5),
                    'good' => mt_rand(-30, 0),
                    'average' => mt_rand(-20, 8),
                    default => mt_rand(-10, 35),
                };
                $entry = $base->copy()->addMinutes($weekend ? mt_rand(60, 240) : $shift);

                // Событий из будущего не бывает: у кого пара после полудня, тот
                // сегодня ещё не пришёл, и на экране он «Не пришёл» — состояние
                // настоящее, а не выдуманное.
                if ($entry->greaterThan($now)) {
                    continue;
                }

                // Пропуск не прочитался с первого раза — человек прикладывает снова.
                if (mt_rand(1, 100) <= 2) {
                    $rows[] = $this->accessRow($identity, $point, AccessEvent::DIRECTION_IN, $entry->copy()->subMinutes(1), $now, AccessEvent::RESULT_DENIED, 'Пропуск не прочитан, повторное прикладывание.');
                }

                $rows[] = $this->accessRow($identity, $point, AccessEvent::DIRECTION_IN, $entry, $now);

                // Выход на обед и возвращение.
                if (! $weekend && mt_rand(1, 100) <= 12) {
                    $lunch = $day->copy()->setTime(11, 45)->addMinutes(mt_rand(0, 40));
                    $back = $lunch->copy()->addMinutes(mt_rand(20, 55));

                    if ($back->lessThanOrEqualTo($now)) {
                        $rows[] = $this->accessRow($identity, $point, AccessEvent::DIRECTION_OUT, $lunch, $now);
                        $rows[] = $this->accessRow($identity, $point, AccessEvent::DIRECTION_IN, $back, $now);
                    }
                }

                // Часть людей уходит, не отметившись: в отчёте они остаются
                // «в здании», и это настоящая, а не выдуманная проблема проходной.
                if (mt_rand(1, 100) <= 88) {
                    $lastLesson = $window[$day->toDateString().'|'.$key.'|end'] ?? null;
                    // Приходящий преподаватель уходит следом за своим занятием,
                    // а не сидит до вечера. У остальных день кончается как
                    // прежде: у преподавателя позже, у студента раньше.
                    $exit = $this->isVisitingTeacher($teacher, $identity->entity_id) && $lastLesson !== null
                        ? $day->copy()->setTimeFromTimeString($lastLesson)->addMinutes(mt_rand(2, 20))
                        : $day->copy()->setTime($teacher ? 16 : 14, 20)->addMinutes(mt_rand(0, 150));

                    if ($exit->greaterThan($entry) && $exit->lessThanOrEqualTo($now)) {
                        $rows[] = $this->accessRow($identity, $point, AccessEvent::DIRECTION_OUT, $exit, $now);
                    }
                }
            }
        }

        if ($rows !== []) {
            AccessEvent::query()->insert($rows);
        }
    }

    /**
     * Начало первой пары по дням: у преподавателя своё, у группы своё.
     *
     * Ключ — «дата|teacher:N» или «дата|group:N». Студент берёт время у своей
     * группы: расписание составляется на группу, а не на человека.
     *
     * @param \Illuminate\Support\Collection<int, ScheduleLesson> $lessons
     * @return array<string, string>
     */
    private function lessonWindows($lessons): array
    {
        $starts = [];

        foreach ($lessons as $lesson) {
            $date = $lesson->lesson_date->toDateString();
            $time = $this->timeString($lesson->starts_at);

            $ends = $this->timeString($lesson->ends_at);

            foreach (['teacher:'.$lesson->teacher_id, 'group:'.$lesson->group_id] as $key) {
                $index = $date.'|'.$key;

                if (! isset($starts[$index]) || $time < $starts[$index]) {
                    $starts[$index] = $time;
                }

                if (! isset($starts[$index.'|end']) || $ends > $starts[$index.'|end']) {
                    $starts[$index.'|end'] = $ends;
                }
            }
        }

        return $starts;
    }

    /**
     * Приходящий преподаватель — тот же признак, что и в кадровой карточке:
     * каждый четвёртый. Считается по порядковому номеру в наборе, а не по
     * идентификатору строки.
     */
    private function isVisitingTeacher(bool $isTeacher, int $teacherId): bool
    {
        return $isTeacher && $this->ordinal($this->teacherOrdinals, $teacherId) % 4 === 0;
    }

    /** @return array<string, mixed> */
    private function accessRow(DigitalIdentity $identity, AccessPoint $point, string $direction, Carbon $time, Carbon $now, string $result = AccessEvent::RESULT_ALLOWED, ?string $reason = null): array
    {
        return [
            'digital_identity_id' => $identity->id,
            'entity_type' => $identity->entity_type,
            'entity_id' => $identity->entity_id,
            // Строка остаётся: событие проходной обязано пережить переименование
            // и удаление точки. Связь — чтобы отчёт знал корпус.
            'access_point' => $point->name,
            'access_point_id' => $point->id,
            'device_name' => 'Демо-турникет',
            'direction' => $direction,
            'event_time' => $time,
            'result' => $result,
            'reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Записать строку по ключам: найденную обновить, недостающую создать.
     *
     * Это `updateOrCreate` без точки сохранения, и весь набор пишет строки
     * только так. С Laravel 10.31 `updateOrCreate` и `firstOrCreate` создают
     * строку через `createOrFirst`, а тот внутри уже открытой транзакции
     * заворачивает вставку в `SAVEPOINT` (`Eloquent\Builder::withSavepointIfNeeded`)
     * — чтобы поймать нарушение уникальности при гонке и подобрать чужую строку.
     * Гонки в сидере нет, а цена есть: каждая точка сохранения получает
     * собственный идентификатор транзакции и держит на нём блокировку **до конца
     * внешней** транзакции.
     *
     * Замер 12.08.2026 на DEV: набор открывал 4970 таких точек за прогон и один
     * занимал 78 % таблицы блокировок сервера (`max_locks_per_transaction` 64 ×
     * `max_connections` 100 = 6400 записей на всех). Второй такой прогон рядом
     * — и `SQLSTATE[53200] out of shared memory`. Под `RefreshDatabase` внешняя
     * транзакция есть всегда, поэтому в прогоне это накапливалось до конца теста.
     *
     * @template TModel of Model
     * @param  Builder<TModel>|Relation<TModel, Model, TModel>  $query
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     * @return TModel
     */
    private function writeRow(Builder|Relation $query, array $keys, array $values): Model
    {
        $row = $query->firstOrNew($keys);
        $row->fill($values)->save();

        return $row;
    }

    private function seedPerson(string $lastName, string $firstName, ?string $middleName, ?string $birthDate, string $email, string $phone): Person
    {
        $person = Person::firstOrNew(['email' => $email]);
        $person->fill([
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'birth_date' => $birthDate,
            'phone' => $phone,
            'status' => 'active',
        ]);
        if (empty($person->uuid)) {
            $person->uuid = (string) Str::uuid();
        }
        $person->save();

        return $person;
    }

    private function seedEmployeeForTeacher(Person $person, int $index, string $departmentName, string $positionName, array $hr): void
    {
        $department = $hr['departments'][$departmentName] ?? null;
        $position = $hr['positions'][$positionName] ?? null;

        // Каждый четвёртый преподаватель — приходящий: пришёл к своему занятию
        // и ушёл. В колледже искусств так работают концертмейстеры и мастера по
        // инструменту, и отчёт обязан отличать их от тех, кто в колледже весь
        // день, иначе нормальный уход после пары выглядит нарушением.
        $visiting = $index % 4 === 0;

        $employee = $this->writeRow(
            Employee::query(),
            ['employee_number' => sprintf('DEMO-T%03d', $index)],
            [
                'person_id' => $person->id,
                'status' => 'active',
                'employment_type' => $visiting ? 'external_part_time' : 'full_time',
                'work_schedule_code' => $visiting ? 'flexible' : 'weekday_0900_1800',
                'hired_at' => Carbon::create(2024, 9, 1)->toDateString(),
                'dismissed_at' => null,
                'primary_department_id' => $department?->id,
                'primary_position_id' => $position?->id,
                'workload_rate' => $visiting ? 0.5 : 1,
                'is_teacher' => true,
                'comment' => 'Демонстрационная кадровая запись преподавателя.',
            ]
        );

        if ($department && $position) {
            $employee->assignments()
                ->where('comment', 'Демонстрационное основное назначение.')
                ->delete();

            EmployeeAssignment::create([
                'employee_id' => $employee->id,
                'department_id' => $department->id,
                'position_id' => $position->id,
                'employment_type' => 'full_time',
                'rate' => 1,
                'started_at' => Carbon::create(2024, 9, 1)->toDateString(),
                'is_primary' => true,
                'comment' => 'Демонстрационное основное назначение.',
            ]);
        }
    }

    private function seedApplicantApplications($programs): void
    {
        $statuses = ['new', 'accepted', 'needs_clarification', 'recommended', 'enrolled'];

        foreach (range(1, 60) as $index) {
            $program = $programs[($index - 1) % $programs->count()];
            $this->writeRow(
                ApplicantApplication::query(),
                [
                    'email' => sprintf('applicant.demo.%03d@%s', $index, self::DEMO_DOMAIN),
                    'record_type' => ApplicantApplication::RECORD_TYPE_LEGACY,
                ],
                [
                    'last_name' => 'Абитуриент',
                    'first_name' => sprintf('Демо%03d', $index),
                    'middle_name' => 'Тестовый',
                    'birth_date' => Carbon::create(2008, (($index - 1) % 12) + 1, (($index - 1) % 24) + 1)->toDateString(),
                    'phone' => sprintf('+7920%07d', 1000000 + $index),
                    'education_base' => $index % 4 === 0 ? 'after_11' : 'after_9',
                    'status' => $statuses[($index - 1) % count($statuses)],
                    'submitted_at' => Carbon::create(2026, 6, 1)->addDays($index)->toDateString(),
                    'comment' => 'Демонстрационное заявление приемной кампании.',
                    'education_program_id' => $program->id,
                ],
            );
        }
    }
}
