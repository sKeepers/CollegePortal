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

    public function run(): void
    {
        DB::transaction(function (): void {
            $adminRole = Role::where('code', 'admin')->firstOrFail();
            $teacherRole = Role::where('code', 'teacher')->firstOrFail();
            $studentRole = Role::where('code', 'student')->firstOrFail();
            $demoPassword = env('DEMO_USER_PASSWORD', 'test1234');

            User::updateOrCreate(
                ['email' => 'admin@college-portal.local'],
                [
                    'role_id' => $adminRole->id,
                    'name' => 'Администратор DEV',
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
        $lastNames = ['Смирнова', 'Петров', 'Орлова', 'Климов', 'Соколова', 'Никитин', 'Федорова', 'Лебедев', 'Егорова', 'Макаров'];
        $firstNames = ['Елена', 'Алексей', 'Марина', 'Игорь', 'Анна', 'Павел', 'Ольга', 'Дмитрий', 'Наталья', 'Сергей'];
        $middleNames = ['Викторовна', 'Андреевич', 'Петровна', 'Сергеевич', 'Павловна', 'Ильич', 'Романовна', 'Олегович', 'Игоревна', 'Михайлович'];

        return collect(range(1, self::TEACHER_COUNT))->map(function (int $index) use ($teacherRole, $demoPassword, $lastNames, $firstNames, $middleNames, $hr): Teacher {
            $email = $index === 1 ? 'teacher@college-portal.local' : sprintf('teacher.demo.%03d@%s', $index, self::DEMO_DOMAIN);
            $lastName = $lastNames[($index - 1) % count($lastNames)];
            $firstName = $firstNames[intdiv($index - 1, count($lastNames)) % count($firstNames)];
            $middleName = $middleNames[intdiv($index - 1, count($lastNames) * count($firstNames)) % count($middleNames)];
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
        $lastNames = ['Иванов', 'Соколова', 'Миронов', 'Кузнецова', 'Попов', 'Васильева', 'Новиков', 'Романова', 'Крылов', 'Зайцева'];
        $firstNames = ['Дмитрий', 'Анна', 'Кирилл', 'Полина', 'Илья', 'Софья', 'Артем', 'Дарья', 'Максим', 'Ева'];
        $middleNames = ['Сергеевич', 'Павловна', 'Игоревич', 'Олеговна', 'Андреевич', 'Ильинична', 'Романович', 'Денисовна', 'Петрович', 'Алексеевна'];

        return collect(range(1, self::STUDENT_COUNT))->map(function (int $index) use ($studentRole, $demoPassword, $groups, $lastNames, $firstNames, $middleNames): Student {
            $email = $index === 1 ? 'student@college-portal.local' : sprintf('student.demo.%03d@%s', $index, self::DEMO_DOMAIN);
            $lastName = $lastNames[($index - 1) % count($lastNames)];
            $firstName = $firstNames[intdiv($index - 1, count($lastNames)) % count($firstNames)];
            $middleName = $middleNames[intdiv($index - 1, count($lastNames) * count($firstNames)) % count($middleNames)];
            $group = $groups[($index - 1) % $groups->count()];
            $birthDate = Carbon::create(2006 + ($group->course % 4), (($index - 1) % 12) + 1, (($index - 1) % 24) + 1)->toDateString();
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

    private function seedWeeklySchedule($groups, $teachers, $subjects, $classrooms)
    {
        $startDate = Carbon::today()->startOfWeek();
        $times = [['08:30', '10:00'], ['10:10', '11:40'], ['12:10', '13:40'], ['13:50', '15:20']];
        $lessons = collect();

        foreach ($groups as $groupIndex => $group) {
            foreach (range(0, 4) as $dayOffset) {
                foreach ($times as $slotIndex => [$startsAt, $endsAt]) {
                    $teacher = $teachers[($groupIndex + $slotIndex + $dayOffset) % $teachers->count()];
                    $subject = $subjects[($groupIndex + $slotIndex) % $subjects->count()];
                    $classroom = $classrooms[($groupIndex + $slotIndex + $dayOffset) % $classrooms->count()];
                    $lessons->push(ScheduleLesson::updateOrCreate(
                        [
                            'group_id' => $group->id,
                            'teacher_id' => $teacher->id,
                            'subject_id' => $subject->id,
                            'lesson_date' => $startDate->copy()->addDays($dayOffset)->toDateString(),
                            'starts_at' => $startsAt,
                        ],
                        [
                            'classroom_id' => $classroom->id,
                            'ends_at' => $endsAt,
                            'lesson_type' => $slotIndex === 3 ? 'practice' : 'lesson',
                            'topic' => 'Демонстрационная тема занятия',
                        ]
                    ));
                }
            }
        }

        return $lessons;
    }

    private function seedJournalSamples($lessons, $students): void
    {
        $studentsByGroup = $students->groupBy('group_id');

        foreach ($lessons->take(180) as $lessonIndex => $lesson) {
            foreach (($studentsByGroup[$lesson->group_id] ?? collect())->take(12) as $studentIndex => $student) {
                $status = $studentIndex % 11 === 0 ? 'late' : ($studentIndex % 17 === 0 ? 'absent' : 'present');
                Attendance::updateOrCreate(
                    ['schedule_lesson_id' => $lesson->id, 'student_id' => $student->id],
                    ['status' => $status, 'comment' => $status === 'late' ? 'Опоздание 10 минут.' : null]
                );

                if ($studentIndex % 4 !== 0) {
                    Grade::updateOrCreate(
                        ['schedule_lesson_id' => $lesson->id, 'student_id' => $student->id, 'grade_type' => 'classwork'],
                        ['grade' => (string) (3 + (($lessonIndex + $studentIndex) % 3)), 'comment' => 'Демо-оценка за работу на занятии.']
                    );
                }
            }
        }
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

    private function seedAccessEvents($students, $teachers): void
    {
        $identities = DigitalIdentity::query()
            ->where(function ($query) use ($students, $teachers): void {
                $query
                    ->where(fn ($studentQuery) => $studentQuery->where('entity_type', DigitalIdentity::ENTITY_STUDENT)->whereIn('entity_id', $students->take(120)->pluck('id')))
                    ->orWhere(fn ($teacherQuery) => $teacherQuery->where('entity_type', DigitalIdentity::ENTITY_TEACHER)->whereIn('entity_id', $teachers->take(25)->pluck('id')));
            })
            ->get();

        if ($identities->isNotEmpty()) {
            AccessEvent::query()
                ->whereIn('digital_identity_id', $identities->pluck('id'))
                ->where('device_name', 'Демо-турникет')
                ->delete();
        }

        foreach ($identities->values() as $index => $identity) {
            if ($index % 17 === 0) {
                AccessEvent::create([
                    'digital_identity_id' => $identity->id,
                    'entity_type' => $identity->entity_type,
                    'entity_id' => $identity->entity_id,
                    'access_point' => 'Главный вход',
                    'device_name' => 'Демо-турникет',
                    'direction' => AccessEvent::DIRECTION_IN,
                    'event_time' => Carbon::today()->setTime(8, 15)->addMinutes($index % 8),
                    'result' => AccessEvent::RESULT_DENIED,
                    'reason' => 'Демо-отказ: пропуск требует проверки.',
                ]);
                continue;
            }

            if ($index % 13 === 0) {
                continue;
            }

            $offset = match (true) {
                $index % 11 === 0 => 16 + ($index % 22),
                $index % 7 === 0 => 1 + ($index % 12),
                default => -25 + ($index % 20),
            };
            $entryTime = Carbon::today()->setTime(8, 30)->addMinutes($offset);

            AccessEvent::create([
                'digital_identity_id' => $identity->id,
                'entity_type' => $identity->entity_type,
                'entity_id' => $identity->entity_id,
                'access_point' => 'Главный вход',
                'device_name' => 'Демо-турникет',
                'direction' => AccessEvent::DIRECTION_IN,
                'event_time' => $entryTime,
                'result' => AccessEvent::RESULT_ALLOWED,
                'reason' => null,
            ]);

            if ($index % 5 !== 0) {
                AccessEvent::create([
                    'digital_identity_id' => $identity->id,
                    'entity_type' => $identity->entity_type,
                    'entity_id' => $identity->entity_id,
                    'access_point' => 'Главный вход',
                    'device_name' => 'Демо-турникет',
                    'direction' => AccessEvent::DIRECTION_OUT,
                    'event_time' => Carbon::today()->setTime(14, 20)->addMinutes($index % 70),
                    'result' => AccessEvent::RESULT_ALLOWED,
                    'reason' => null,
                ]);
            }
        }
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
