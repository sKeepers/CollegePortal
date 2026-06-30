<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\ApplicantApplication;
use App\Models\Classroom;
use App\Models\EducationProgram;
use App\Models\Grade;
use App\Models\Group;
use App\Models\Role;
use App\Models\ScheduleLesson;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('code', 'admin')->firstOrFail();
        $teacherRole = Role::where('code', 'teacher')->firstOrFail();
        $studentRole = Role::where('code', 'student')->firstOrFail();

        User::updateOrCreate(
            ['email' => 'admin@college-portal.local'],
            [
                'role_id' => $adminRole->id,
                'name' => 'Администратор системы',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $teacherUser = User::updateOrCreate(
            ['email' => 'teacher@college-portal.local'],
            [
                'role_id' => $teacherRole->id,
                'name' => 'Смирнова Елена Викторовна',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $teacher = Teacher::updateOrCreate(
            ['email' => 'teacher@college-portal.local'],
            [
                'user_id' => $teacherUser->id,
                'last_name' => 'Смирнова',
                'first_name' => 'Елена',
                'middle_name' => 'Викторовна',
                'phone' => '+79990000001',
                'position' => 'Преподаватель теоретических дисциплин',
                'department' => 'Музыкальное отделение',
                'is_active' => true,
            ]
        );

        $educationProgram = $this->seedApplicantPrograms();

        $group = Group::query()
            ->whereIn('name', ['ИСП-101', 'M-101'])
            ->firstOrNew();
        $group->fill([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'education_program_id' => $educationProgram->id,
            'course' => 1,
            'year_start' => 2026,
            'curator_id' => $teacher->id,
        ])->save();

        $subject = Subject::updateOrCreate(
            ['code' => 'MUS-101'],
            [
                'name' => 'Сольфеджио',
                'department' => 'Музыкальное отделение',
                'description' => 'Базовая дисциплина для студентов первого курса.',
            ]
        );
        $subject->teachers()->syncWithoutDetaching([$teacher->id]);

        $classroom = Classroom::updateOrCreate(
            ['number' => '201', 'building' => 'Главный корпус'],
            [
                'floor' => 2,
                'capacity' => 24,
                'type' => 'Класс теоретических дисциплин',
                'description' => 'Аудитория с фортепиано и проектором.',
            ]
        );

        $studentUser = User::updateOrCreate(
            ['email' => 'student@college-portal.local'],
            [
                'role_id' => $studentRole->id,
                'name' => 'Иванов Дмитрий Сергеевич',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $student = Student::updateOrCreate(
            ['email' => 'student@college-portal.local'],
            [
                'user_id' => $studentUser->id,
                'group_id' => $group->id,
                'last_name' => 'Иванов',
                'first_name' => 'Дмитрий',
                'middle_name' => 'Сергеевич',
                'birth_date' => '2009-05-12',
                'phone' => '+79990000002',
                'status' => 'active',
                'enrollment_date' => '2026-09-01',
            ]
        );

        Student::updateOrCreate(
            ['email' => 'student2@college-portal.local'],
            [
                'user_id' => null,
                'group_id' => $group->id,
                'last_name' => 'Соколова',
                'first_name' => 'Анна',
                'middle_name' => 'Павловна',
                'birth_date' => '2009-08-23',
                'phone' => '+79990000003',
                'status' => 'active',
                'enrollment_date' => '2026-09-01',
            ]
        );

        $lesson = ScheduleLesson::updateOrCreate(
            [
                'group_id' => $group->id,
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'lesson_date' => '2026-09-02',
                'starts_at' => '09:00',
            ],
            [
                'classroom_id' => $classroom->id,
                'ends_at' => '10:30',
                'lesson_type' => 'lesson',
                'topic' => 'Введение в нотную грамоту',
            ]
        );

        Attendance::updateOrCreate(
            ['schedule_lesson_id' => $lesson->id, 'student_id' => $student->id],
            ['status' => 'present', 'comment' => 'Присутствовал на занятии.']
        );

        Grade::updateOrCreate(
            ['schedule_lesson_id' => $lesson->id, 'student_id' => $student->id, 'grade_type' => 'classwork'],
            ['grade' => '5', 'comment' => 'Активная работа на занятии.']
        );

        $this->seedApplicantApplications();
    }

    private function seedApplicantPrograms(): EducationProgram
    {
        $educationLevel = 'Среднее профессиональное образование - программа подготовки специалистов среднего звена';
        $programs = [
            [
                'code' => '53.02.02',
                'specialty' => 'Музыкальное искусство эстрады',
                'qualification' => 'Артист, преподаватель, руководитель эстрадного коллектива',
                'program' => 'ППССЗ Музыкальное искусство эстрады',
                'study_form' => 'Очная',
                'study_years' => 3.8,
                'description' => 'Виды: инструменты эстрадного оркестра, эстрадное пение. Срок обучения: 3 года 10 месяцев.',
            ],
            [
                'code' => '53.02.03',
                'specialty' => 'Инструментальное исполнительство',
                'qualification' => 'Артист, преподаватель, концертмейстер',
                'program' => 'ППССЗ Инструментальное исполнительство',
                'study_form' => 'Очная',
                'study_years' => 3.8,
                'description' => 'Виды: фортепиано, оркестровые струнные инструменты, оркестровые духовые и ударные инструменты, инструменты народного оркестра. Срок обучения: 3 года 10 месяцев.',
            ],
            [
                'code' => '53.02.04',
                'specialty' => 'Вокальное искусство',
                'qualification' => 'Артист-вокалист, преподаватель',
                'program' => 'ППССЗ Вокальное искусство',
                'study_form' => 'Очная',
                'study_years' => 3.8,
                'description' => 'Срок обучения: 3 года 10 месяцев.',
            ],
            [
                'code' => '53.02.05',
                'specialty' => 'Сольное и хоровое народное пение',
                'qualification' => 'Артист-вокалист, преподаватель, руководитель народного коллектива',
                'program' => 'ППССЗ Сольное и хоровое народное пение',
                'study_form' => 'Очная',
                'study_years' => 3.8,
                'description' => 'Вид: хоровое народное пение. Срок обучения: 3 года 10 месяцев.',
            ],
            [
                'code' => '53.02.06',
                'specialty' => 'Хоровое дирижирование',
                'qualification' => 'Дирижер хора, преподаватель',
                'program' => 'ППССЗ Хоровое дирижирование',
                'study_form' => 'Очная',
                'study_years' => 3.8,
                'description' => 'Срок обучения: 3 года 10 месяцев.',
            ],
            [
                'code' => '53.02.07',
                'specialty' => 'Теория музыки',
                'qualification' => 'Преподаватель, организатор музыкально-просветительской деятельности',
                'program' => 'ППССЗ Теория музыки',
                'study_form' => 'Очная',
                'study_years' => 3.8,
                'description' => 'Срок обучения: 3 года 10 месяцев.',
            ],
            [
                'code' => '51.02.01',
                'specialty' => 'Народное художественное творчество',
                'qualification' => 'Руководитель любительского творческого коллектива, преподаватель',
                'program' => 'ППССЗ Народное художественное творчество',
                'study_form' => 'Очная',
                'study_years' => 3.8,
                'description' => 'Виды: хореографическое творчество, театральное творчество. Срок обучения: 3 года 10 месяцев.',
            ],
            [
                'code' => '51.02.02',
                'specialty' => 'Социально-культурная деятельность',
                'qualification' => 'Менеджер социально-культурной деятельности',
                'program' => 'ППССЗ Социально-культурная деятельность после 9 класса',
                'study_form' => 'Очная',
                'study_years' => 3.8,
                'description' => 'Прием после 9 класса. Срок обучения: 3 года 10 месяцев.',
            ],
            [
                'code' => '51.02.02',
                'specialty' => 'Социально-культурная деятельность',
                'qualification' => 'Менеджер социально-культурной деятельности',
                'program' => 'ППССЗ Социально-культурная деятельность после 9 класса',
                'study_form' => 'Заочная',
                'study_years' => 3.8,
                'description' => 'Прием после 9 класса. Срок обучения: 3 года 10 месяцев.',
            ],
            [
                'code' => '51.02.02',
                'specialty' => 'Социально-культурная деятельность',
                'qualification' => 'Менеджер социально-культурной деятельности',
                'program' => 'ППССЗ Социально-культурная деятельность после 11 класса',
                'study_form' => 'Заочная',
                'study_years' => 2.8,
                'description' => 'Прием после 11 класса. Срок обучения: 2 года 10 месяцев.',
            ],
            [
                'code' => '51.02.03',
                'specialty' => 'Библиотечно-информационная деятельность',
                'qualification' => 'Специалист по библиотечно-информационной деятельности',
                'program' => 'ППССЗ Библиотечно-информационная деятельность',
                'study_form' => 'Очная',
                'study_years' => 2.8,
                'description' => 'Срок обучения: 2 года 10 месяцев.',
            ],
            [
                'code' => '51.02.03',
                'specialty' => 'Библиотечно-информационная деятельность',
                'qualification' => 'Специалист по библиотечно-информационной деятельности',
                'program' => 'ППССЗ Библиотечно-информационная деятельность',
                'study_form' => 'Заочная',
                'study_years' => 2.8,
                'description' => 'Срок обучения: 2 года 10 месяцев.',
            ],
        ];

        $defaultProgram = null;

        foreach ($programs as $item) {
            $specialty = Specialty::updateOrCreate(
                ['code' => $item['code']],
                [
                    'name' => $item['specialty'],
                    'education_level' => $educationLevel,
                    'qualification' => $item['qualification'],
                    'normative_study_years' => $item['study_years'],
                    'description' => $item['description'],
                ]
            );

            $program = EducationProgram::updateOrCreate(
                [
                    'specialty_id' => $specialty->id,
                    'name' => $item['program'],
                    'year_start' => 2026,
                    'study_form' => $item['study_form'],
                ],
                [
                    'study_years' => $item['study_years'],
                    'is_active' => true,
                    'description' => $item['description'],
                ]
            );

            if ($item['code'] === '53.02.04' && $item['study_form'] === 'Очная') {
                $defaultProgram = $program;
            }
        }

        return $defaultProgram ?? EducationProgram::query()->firstOrFail();
    }

    private function seedApplicantApplications(): void
    {
        $programs = EducationProgram::query()
            ->whereIn('study_form', ['Очная', 'Заочная'])
            ->orderBy('name')
            ->limit(3)
            ->get();

        $applications = [
            [
                'last_name' => 'Анохин',
                'first_name' => 'Дмитрий',
                'middle_name' => 'Алексеевич',
                'birth_date' => '2010-03-14',
                'phone' => '+79990000010',
                'email' => 'anohin@example.test',
                'education_base' => 'after_9',
                'status' => 'new',
                'submitted_at' => '2026-06-20',
                'comment' => 'Первичное обращение, ожидаем документы.',
            ],
            [
                'last_name' => 'Борисова',
                'first_name' => 'Софья',
                'middle_name' => 'Владимировна',
                'birth_date' => '2009-11-02',
                'phone' => '+79990000011',
                'email' => 'borisova@example.test',
                'education_base' => 'after_9',
                'status' => 'accepted',
                'submitted_at' => '2026-06-21',
                'comment' => 'Документы приняты.',
            ],
            [
                'last_name' => 'Казаченко',
                'first_name' => 'Полина',
                'middle_name' => 'Викторовна',
                'birth_date' => '2008-07-19',
                'phone' => '+79990000012',
                'email' => 'kazachenko@example.test',
                'education_base' => 'after_11',
                'status' => 'needs_clarification',
                'submitted_at' => '2026-06-22',
                'comment' => 'Нужно уточнить документ об образовании.',
            ],
        ];

        foreach ($applications as $index => $application) {
            $program = $programs[$index] ?? $programs->first();

            if (! $program) {
                continue;
            }

            ApplicantApplication::updateOrCreate(
                ['email' => $application['email']],
                [
                    ...$application,
                    'education_program_id' => $program->id,
                ],
            );
        }
    }
}
