<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../app/rules/quiz_blockers.php';

final class QuizBlockersTest extends TestCase
{
    private function validQuiz(): array
    {
        return [
            'title'        => 'Routing Practice',
            'class_id'     => 1,
            'opens_at'     => '2026-11-02 08:00:00',
            'closes_at'    => '2026-11-02 09:00:00',
            'duration_min' => 45,
        ];
    }

    private function validQuestion(int $position = 1): array
    {
        return [
            'position' => $position,
            'type'     => 'single',
            'prompt'   => 'Which device forwards packets between networks?',
            'points'   => 2.0,
            'options'  => [
                ['label' => 'Router', 'is_correct' => 1],
                ['label' => 'Switch', 'is_correct' => 0],
            ],
        ];
    }

    public function testValidQuizHasNoBlockers(): void
    {
        $quiz = $this->validQuiz();
        $questions = [$this->validQuestion(1), $this->validQuestion(2)];

        $blockers = quiz_blockers($quiz, $questions);
        $this->assertEmpty($blockers);
    }

    public function testMissingTitleAndClass(): void
    {
        $quiz = $this->validQuiz();
        $quiz['title'] = '   ';
        $quiz['class_id'] = 0;

        $blockers = quiz_blockers($quiz, [$this->validQuestion(1)]);
        $this->assertContains('Quiz title is required.', $blockers);
        $this->assertContains('A class must be selected.', $blockers);
    }

    public function testClosesBeforeItOpens(): void
    {
        $quiz = $this->validQuiz();
        $quiz['opens_at'] = '2026-11-02 10:00:00';
        $quiz['closes_at'] = '2026-11-02 09:00:00';

        $blockers = quiz_blockers($quiz, [$this->validQuestion(1)]);
        $this->assertContains('Closes before it opens.', $blockers);
    }

    public function testMissingDates(): void
    {
        $quiz = $this->validQuiz();
        $quiz['opens_at'] = null;
        $quiz['closes_at'] = null;

        $blockers = quiz_blockers($quiz, [$this->validQuestion(1)]);
        $this->assertContains('Opening and closing schedule dates are required.', $blockers);
    }

    public function testEmptyQuestionsList(): void
    {
        $quiz = $this->validQuiz();
        $blockers = quiz_blockers($quiz, []);

        $this->assertContains('No questions added yet.', $blockers);
    }

    public function testQuestionMissingCorrectAnswer(): void
    {
        $quiz = $this->validQuiz();
        $q = $this->validQuestion(4);
        $q['options'] = [
            ['label' => 'A', 'is_correct' => 0],
            ['label' => 'B', 'is_correct' => 0],
        ];

        $blockers = quiz_blockers($quiz, [$q]);
        $this->assertContains('Question 4 has no correct answer.', $blockers);
    }

    public function testQuestionWithZeroPoints(): void
    {
        $quiz = $this->validQuiz();
        $q = $this->validQuestion(2);
        $q['points'] = 0.0;

        $blockers = quiz_blockers($quiz, [$q]);
        $this->assertContains('Question 2 has 0 points.', $blockers);
    }

    public function testSingleChoiceMultipleCorrectAnswers(): void
    {
        $quiz = $this->validQuiz();
        $q = $this->validQuestion(3);
        $q['type'] = 'single';
        $q['options'] = [
            ['label' => 'A', 'is_correct' => 1],
            ['label' => 'B', 'is_correct' => 1],
        ];

        $blockers = quiz_blockers($quiz, [$q]);
        $this->assertContains('Question 3 has multiple correct answers.', $blockers);
    }

    public function testMultiSelectMultipleCorrectAnswersIsAllowed(): void
    {
        $quiz = $this->validQuiz();
        $q = $this->validQuestion(1);
        $q['type'] = 'multi';
        $q['options'] = [
            ['label' => '10.0.0.0/8', 'is_correct' => 1],
            ['label' => '172.16.0.0/12', 'is_correct' => 1],
            ['label' => '8.8.8.8', 'is_correct' => 0],
        ];

        $blockers = quiz_blockers($quiz, [$q]);
        $this->assertEmpty($blockers);
    }

    public function testQuestionWithFewerThanTwoOptions(): void
    {
        $quiz = $this->validQuiz();
        $q = $this->validQuestion(1);
        $q['options'] = [
            ['label' => 'Only one', 'is_correct' => 1],
        ];

        $blockers = quiz_blockers($quiz, [$q]);
        $this->assertContains('Question 1 has fewer than 2 options.', $blockers);
    }
}
