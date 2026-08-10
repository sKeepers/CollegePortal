<?php

namespace Database\Seeders;

use App\Models\AccessEvent;
use App\Models\Attendance;
use App\Models\ApplicantApplication;
use App\Models\Classroom;
use App\Models\DigitalIdentity;
use App\Models\Department;
use App\Models\EducationProgram;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Grade;
use App\Models\Group;
use App\Models\Person;
use App\Models\Position;
use App\Models\Role;
use App\Models\ScheduleLesson;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\Support\DemoNameFactory;
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

    public function run(): void
    {
        $this->names = new DemoNameFactory();

        DB::transaction(function (): void {
            $adminRole = Role::where('code', 'admin')->firstOrFail();
            $teacherRole = Role::where('code', 'teacher')->firstOrFail();
            $studentRole = Role::where('code', 'student')->firstOrFail();
            $demoPassword = env('DEMO_USER_PASSWORD', 'test1234');

            User::updateOrCreate(
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
            $lessons = $this->seedWeeklySchedule($groups, $teachers, $subjects, $classrooms);
            $this->seedJournalSamples($lessons, $students);
            $this->seedDigitalIdentities($students, $teachers);
            $this->seedAccessEvents($students, $teachers);
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
            $specialty = Specialty::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $specialtyName,
                    'education_level' => $educationLevel,
                    'qualification' => $qualification,
                    'normative_study_years' => $studyYears,
                    'description' => 'Демонстрационная специальность для DEV-стенда.',
                ]
            );

            return EducationProgram::updateOrCreate(
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

        return collect($items)->map(fn (string $name, int $index): Subject => Subject::updateOrCreate(
            ['code' => $index === 0 ? 'MUS-101' : sprintf('DEMO-SUB-%02d', $index + 1)],
            [
                'name' => $name,
                'department' => $index % 3 === 0 ? 'Музыкальное отделение' : 'Общеобразовательное отделение',
                'description' => 'Демонстрационная дисциплина.',
            ]
        ))->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Classroom>
     */
    private function seedClassrooms()
    {
        return collect(range(1, 25))->map(fn (int $index): Classroom => Classroom::updateOrCreate(
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
            $item[1] => Department::updateOrCreate(
                ['code' => $item[0]],
                ['name' => $item[1], 'type' => 'academic', 'is_active' => true]
            ),
        ]);

        $positions = collect([
            ['DEMO-TEACHER', 'Преподаватель', true],
            ['DEMO-DEPARTMENT-HEAD', 'Заведующий отделением', true],
        ])->mapWithKeys(fn (array $item): array => [
            $item[1] => Position::updateOrCreate(
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
                ? User::updateOrCreate(
                    ['email' => $email],
                    ['role_id' => $teacherRole->id, 'person_id' => $person->id, 'person_type' => 'person', 'name' => "{$lastName} {$firstName} {$middleName}", 'password' => Hash::make($demoPassword), 'is_active' => true]
                )
                : null;

            $teacher = Teacher::updateOrCreate(
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

            return Group::updateOrCreate(
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
                ? User::updateOrCreate(
                    ['email' => $email],
                    ['role_id' => $studentRole->id, 'person_id' => $person->id, 'person_type' => 'person', 'name' => "{$lastName} {$firstName} {$middleName}", 'password' => Hash::make($demoPassword), 'is_active' => true]
                )
                : null;

            return Student::updateOrCreate(
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
                    $lessons->push(ScheduleLesson::updateOrCreate(
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
    private function seedJournalSamples($lessons, $students): void
    {
        $studentsByGroup = $students->groupBy('group_id');
        $past = $lessons->filter(fn (ScheduleLesson $lesson): bool => $lesson->lesson_date <= Carbon::today()->toDateString());
        $lessonIds = $past->pluck('id');

        Attendance::query()->whereIn('schedule_lesson_id', $lessonIds)->delete();
        Grade::query()->whereIn('schedule_lesson_id', $lessonIds)->delete();

        $now = now();
        $attendanceRows = [];
        $gradeRows = [];

        foreach ($past as $lesson) {
            foreach (($studentsByGroup[$lesson->group_id] ?? collect()) as $student) {
                $profile = $this->studentProfile($student->id);
                // Провальная дисциплина своя у каждого пятого: ровный студент,
                // просевший на одном предмете, — обычная картина ведомости.
                $struggles = $student->id % 5 === 0 && $lesson->subject_id % 4 === $student->id % 4;
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
    }

    /**
     * Уровень студента устойчив между запусками: он выводится из его же
     * идентификатора, а не бросается заново. Иначе отличник при следующем
     * наполнении стенда становится отстающим, и сравнить два прогона нельзя.
     */
    private function studentProfile(int $studentId): string
    {
        $bucket = ($studentId * 7919) % 100;

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
        $bucket = ($teacherId * 6421) % 100;

        return match (true) {
            $bucket < 70 => 'excellent',
            $bucket < 92 => 'good',
            default => 'weak',
        };
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
            DigitalIdentity::updateOrCreate(
                ['entity_type' => DigitalIdentity::ENTITY_STUDENT, 'entity_id' => $student->id],
                ['person_id' => $student->person_id, 'token' => (string) Str::uuid(), 'status' => DigitalIdentity::STATUS_ACTIVE, 'issued_at' => now(), 'expires_at' => null, 'revoked_at' => null]
            );
        }

        foreach ($teachers as $teacher) {
            DigitalIdentity::updateOrCreate(
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
    private function seedAccessEvents($students, $teachers): void
    {
        $identities = DigitalIdentity::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('entity_type', DigitalIdentity::ENTITY_STUDENT)->whereIn('entity_id', $students->pluck('id')))
                ->orWhere(fn ($q) => $q->where('entity_type', DigitalIdentity::ENTITY_TEACHER)->whereIn('entity_id', $teachers->pluck('id'))))
            ->get(['id', 'entity_type', 'entity_id']);

        if ($identities->isEmpty()) {
            return;
        }

        AccessEvent::query()
            ->whereIn('digital_identity_id', $identities->pluck('id'))
            ->where('device_name', 'Демо-турникет')
            ->delete();

        $points = ['Главный вход', 'Главный вход', 'Главный вход', 'Служебный вход', 'Концертный зал'];
        $now = now();
        $rows = [];

        foreach (range(self::HISTORY_DAYS, 0) as $daysAgo) {
            $day = Carbon::today()->subDays($daysAgo);
            $weekend = $day->isWeekend();

            foreach ($identities as $identity) {
                $teacher = $identity->entity_type === DigitalIdentity::ENTITY_TEACHER;
                $profile = $teacher ? $this->teacherProfile($identity->entity_id) : $this->studentProfile($identity->entity_id);

                // По выходным в здании репетиции: приходят единицы.
                if (mt_rand(1, 100) > ($weekend ? 8 : ($teacher ? 94 : 88))) {
                    continue;
                }

                $point = $points[mt_rand(0, count($points) - 1)];
                $base = $day->copy()->setTime(8, 30);
                $shift = match ($profile) {
                    'excellent' => mt_rand(-40, -5),
                    'good' => mt_rand(-30, 0),
                    'average' => mt_rand(-20, 8),
                    default => mt_rand(-10, 35),
                };
                $entry = $base->copy()->addMinutes($weekend ? mt_rand(60, 240) : $shift);

                // Пропуск не прочитался с первого раза — человек прикладывает снова.
                if (mt_rand(1, 100) <= 2) {
                    $rows[] = $this->accessRow($identity, $point, AccessEvent::DIRECTION_IN, $entry->copy()->subMinutes(1), $now, AccessEvent::RESULT_DENIED, 'Пропуск не прочитан, повторное прикладывание.');
                }

                $rows[] = $this->accessRow($identity, $point, AccessEvent::DIRECTION_IN, $entry, $now);

                // Выход на обед и возвращение.
                if (! $weekend && mt_rand(1, 100) <= 12) {
                    $lunch = $day->copy()->setTime(11, 45)->addMinutes(mt_rand(0, 40));
                    $rows[] = $this->accessRow($identity, $point, AccessEvent::DIRECTION_OUT, $lunch, $now);
                    $rows[] = $this->accessRow($identity, $point, AccessEvent::DIRECTION_IN, $lunch->copy()->addMinutes(mt_rand(20, 55)), $now);
                }

                // Часть людей уходит, не отметившись: в отчёте они остаются
                // «в здании», и это настоящая, а не выдуманная проблема проходной.
                if (mt_rand(1, 100) <= 88) {
                    $exit = $day->copy()->setTime($teacher ? 16 : 14, 20)->addMinutes(mt_rand(0, 150));
                    $rows[] = $this->accessRow($identity, $point, AccessEvent::DIRECTION_OUT, $exit, $now);
                }
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            AccessEvent::query()->insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function accessRow(DigitalIdentity $identity, string $point, string $direction, Carbon $time, Carbon $now, string $result = AccessEvent::RESULT_ALLOWED, ?string $reason = null): array
    {
        return [
            'digital_identity_id' => $identity->id,
            'entity_type' => $identity->entity_type,
            'entity_id' => $identity->entity_id,
            'access_point' => $point,
            'device_name' => 'Демо-турникет',
            'direction' => $direction,
            'event_time' => $time,
            'result' => $result,
            'reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ];
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

        $employee = Employee::updateOrCreate(
            ['employee_number' => sprintf('DEMO-T%03d', $index)],
            [
                'person_id' => $person->id,
                'status' => 'active',
                'employment_type' => 'full_time',
                'hired_at' => Carbon::create(2024, 9, 1)->toDateString(),
                'dismissed_at' => null,
                'primary_department_id' => $department?->id,
                'primary_position_id' => $position?->id,
                'workload_rate' => 1,
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
            ApplicantApplication::updateOrCreate(
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
