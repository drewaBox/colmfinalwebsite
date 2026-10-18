<?php
// Tests the School Level / Course / Year Level / Section rules.   Run:  php tests/students_test.php
// It uses the starting list (default_school_structure), so it needs no database.
require_once __DIR__ . '/../backend/students.php';

$failed = 0;
function expect($name, $actual, $wanted) {
    global $failed;
    if ($actual === $wanted) { echo "  PASS  $name\n"; return; }
    $failed++; echo "  FAIL  $name (got " . var_export($actual, true) . ")\n";
}
expect('Default student password is shared', make_default_password(), 'Password123!');
$structure = default_school_structure();

// How many sections does each combination have?  [level, course, year, wanted number of sections]
$wanted = [
    ['COLLEGE', 'BSTM', 1, 3], ['COLLEGE', 'BSTM', 2, 2], ['COLLEGE', 'BSTM', 3, 4], ['COLLEGE', 'BSTM', 4, 2],
    ['SENIOR HIGH SCHOOL', 'STEM', 11, 2], ['SENIOR HIGH SCHOOL', 'HUMSS', 11, 3],
    ['SENIOR HIGH SCHOOL', 'STEM', 12, 1], ['SENIOR HIGH SCHOOL', 'HUMSS', 12, 2],
];
foreach ($wanted as [$level, $course, $year, $sections]) {
    expect("$level / $course / $year has $sections sections", count($structure[$level][$course][$year] ?? []), $sections);
    expect('... the last section is accepted', check_school_combination($level, $course, $year, "Section $sections", $structure), '');
    expect('... one more section is refused', check_school_combination($level, $course, $year, 'Section ' . ($sections + 1), $structure) !== '', true);
}
expect('Only College and Senior High remain active', array_keys($structure), ['COLLEGE', 'SENIOR HIGH SCHOOL']);
expect('College has all 16 programs', array_keys($structure['COLLEGE']), [
    'BSTM',
    'BSA - Accountancy',
    'BS AIS - Accounting Information System',
    'BSBA FM - Financial Management',
    'BSBA MM - Marketing Management',
    'BSEntrep - Entrepreneurship',
    'BSIT - Information Technology',
    'BSCS - Computer Science',
    'BSCRIM - Criminology',
    'BSHM - Hospitality Management',
    'BTVTE - Food and Service Management',
    'BTVTE - Home Economics',
    'BTVTE - Animal and Crop Science',
    'BTLE - Home Economics',
    'BTLE - Agri-Fishery',
    'BSRT - Radiologic Technology',
]);
expect('Every College course has the same year and section setup as BSTM', array_reduce(
    $structure['COLLEGE'],
    fn($valid, $years) => $valid && array_keys($years) === [1, 2, 3, 4]
        && $years === $structure['COLLEGE']['BSTM'],
    true
), true);
expect('Senior high has only Grade 11 and 12', array_keys($structure['SENIOR HIGH SCHOOL']['STEM']), [11, 12]);
expect('Junior High is absent from the student structure', isset($structure['HIGH SCHOOL']), false);
expect('Senior High allowed document names', [
    senior_high_can_request_document('Certificate of Registration (COR)'),
    senior_high_can_request_document('Certificate of Enrollment'),
    senior_high_can_request_document('Form 137 / Permanent Record'),
    senior_high_can_request_document('Form 138 / Report Card'),
    senior_high_can_request_document('Certificate of Good Moral Character'),
    senior_high_can_request_document('Certificate of Graduation'),
], [true, true, true, true, true, true]);
expect('Senior High blocks unrelated document requests', [
    senior_high_can_request_document('Transcript of Records (TOR)'),
    senior_high_can_request_document('Certification of Grades'),
    senior_high_can_request_document('Other Certifications'),
], [false, false, false]);

echo "Wrong combinations are refused\n";
expect('STEM does not belong to COLLEGE', check_school_combination('COLLEGE', 'STEM', 11, 'Section 1', $structure) !== '', true);
expect('BSTM Grade 11 does not exist', check_school_combination('COLLEGE', 'BSTM', 11, 'Section 1', $structure) !== '', true);
expect('BSTM year 5 does not exist', check_school_combination('COLLEGE', 'BSTM', 5, 'Section 1', $structure) !== '', true);
expect('STEM Grade 10 does not exist', check_school_combination('SENIOR HIGH SCHOOL', 'STEM', 10, 'Section 1', $structure) !== '', true);
expect('Junior High enrollment is refused', check_school_combination('HIGH SCHOOL', 'HIGH SCHOOL', 7, 'Section 1', $structure) !== '', true);
expect('Section 0 is refused', check_school_combination('SENIOR HIGH SCHOOL', 'STEM', 11, 'Section 0', $structure) !== '', true);
expect('Unknown school level is refused', check_school_combination('KINDER', 'BSTM', 1, 'Section 1', $structure) !== '', true);

echo "Names: capital letters do not matter, the list decides how it is saved\n";
[$values, $problem] = resolve_school_combination('college', 'bstm', 3, '4', $structure);
expect('"college / bstm / 3 / 4" is accepted', $problem, '');
expect('... and saved as in the list', $values, ['school_level' => 'COLLEGE', 'course' => 'BSTM', 'year' => 3, 'section' => 'Section 4']);
expect('"section4" is understood', find_section(['Section 1', 'Section 4'], 'section4'), 'Section 4');
expect('A section named A (added by the admin) works', find_section(['A', 'B'], 'b'), 'B');

echo "An admin-made list is used (new course, sections A and B)\n";
$custom = ['COLLEGE' => ['BSIT' => [1 => ['A', 'B']]]];
expect('New course BSIT / year 1 / section B is accepted', check_school_combination('COLLEGE', 'BSIT', 1, 'B', $custom), '');
expect('Section C does not exist', check_school_combination('COLLEGE', 'BSIT', 1, 'C', $custom) !== '', true);
expect('An old course that is not in the (active) list is refused for NEW students', check_school_combination('COLLEGE', 'BSTM', 1, 'Section 1', $custom) !== '', true);
expect('The level of a course is found', level_of_course('bsit', $custom), 'COLLEGE');

echo "validate_student\n";
$good = ['student_id' => '2026-10001', 'full_name' => 'Maria Santos', 'email' => 'm@example.com', 'school_level' => 'COLLEGE', 'course' => 'BSTM',
         'year' => '3', 'section' => 'Section 4', 'contact_number' => '09171234567'];
[$data, $errors] = validate_student($good, $structure);
expect('Valid student has no errors', $errors, []);
expect('Section is saved as "Section 4"', $data['section'], 'Section 4');
[, $errors] = validate_student(array_merge($good, ['section' => 'Section 5']), $structure);
expect('BSTM 3rd year Section 5 is refused', count($errors) > 0, true);
[$data, $errors] = validate_student(['student_id' => '2026-10002', 'full_name' => 'Jose Reyes', 'course' => 'STEM', 'year' => 'Grade 11', 'section' => 'Section 2'], $structure);
expect('CSV row without School Level: the course decides it', [$errors, $data['school_level']], [[], 'SENIOR HIGH SCHOOL']);
[, $errors] = validate_student(array_merge($good, ['school_level' => 'HIGH SCHOOL']), $structure);
expect('BSTM inside HIGH SCHOOL is refused', count($errors) > 0, true);
[, $errors] = validate_student(array_merge($good, ['student_id' => '']), $structure);
expect('Missing Student ID is refused', count($errors) > 0, true);
expect('"Third Year" is understood', parse_year_level('Third Year'), 3);
expect('"Grade 12" is understood', parse_year_level('Grade 12'), 12);
expect('Default year labels', [default_year_label('COLLEGE', 1), default_year_label('SENIOR HIGH SCHOOL', 11)], ['First Year', 'Grade 11']);

echo $failed ? "\n$failed failed\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
