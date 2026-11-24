<?php

namespace App\Services\Employee;

use App\Models\SelfService\ContactDetail;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An employee's work email is their login email: `users.email` is the source of truth and
 * `contact_details.work_email` mirrors it. Changes go through change() / changeForUser(), which check the
 * address; the model hooks (syncFromContact / syncFromUser) keep the two in step whatever saves them, and
 * User moves a username that was the old email.
 */
class WorkEmail
{
    /** Set while one side is being copied to the other, so the other side's hook doesn't copy it back. */
    private static bool $syncing = false;

    public static function normalize(?string $email): ?string
    {
        $email = Str::of((string) $email)->replace(' ', '')->lower()->value();

        return $email === '' ? null : $email;
    }

    /** The user's employee, or null: User::employee() has a default, so it is never null itself. */
    public static function employeeOf(User $user): ?Employee
    {
        return $user->employee_id && $user->employee?->exists ? $user->employee : null;
    }

    /**
     * Whether $email is a change. Matching either stored address isn't one: until old records are
     * reconciled the profile form may send back the contact copy, which can differ from the login email.
     */
    public function differs(Employee $employee, ?string $email): bool
    {
        $email = self::normalize($email);

        return $email !== self::normalize($this->current($employee))
            && $email !== self::normalize($employee->contactDetail?->work_email);
    }

    /** The employee's work email: their login email when they have an account. */
    public function current(Employee $employee): ?string
    {
        return $employee->userAccount?->email ?? $employee->contactDetail?->work_email;
    }

    /**
     * @throws ValidationException when another person already uses the address.
     */
    public function assertAvailable(string $email, ?Employee $employee = null, ?User $user = null, string $field = 'work_email'): void
    {
        $email = self::normalize($email);
        $user ??= $employee?->userAccount;

        $takenByUser = User::query()
            // The username follows the email, so it can't be someone else's username either.
            ->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email])->orWhereRaw('LOWER(username) = ?', [$email]))
            ->when($user, fn ($q) => $q->whereKeyNot($user->getKey()))
            ->exists();

        $takenByContact = ContactDetail::query()
            ->whereRaw('LOWER(work_email) = ?', [$email])
            ->when($employee, fn ($q) => $q->where('employee_id', '!=', $employee->id))
            ->exists();

        if ($takenByUser || $takenByContact) {
            throw ValidationException::withMessages([$field => 'This email is already used by someone else.']);
        }
    }

    /** Change an employee's work email, and their login email with it. */
    public function change(Employee $employee, string $email, string $field = 'work_email'): void
    {
        $email = self::normalize($email);
        $this->assertAvailable($email, $employee, field: $field);

        DB::transaction(function () use ($employee, $email) {
            $old = $this->current($employee);

            $employee->contactDetail()->updateOrCreate([], ['work_email' => $email]);

            if ($user = $employee->userAccount) {
                $this->applyToUser($user, $email);
            }

            if (self::normalize($old) !== $email) {
                activity('employees')->performedOn($employee)
                    ->log("Changed work email for {$employee->name} from " . ($old ?: '—') . " to {$email}");
            }
        });
    }

    /** Change a user's email; for an employee's account, their work email changes too. */
    public function changeForUser(User $user, string $email, string $field = 'email'): void
    {
        if ($employee = self::employeeOf($user)) {
            $this->change($employee, $email, $field);

            return;
        }

        $email = self::normalize($email);
        $this->assertAvailable($email, user: $user, field: $field);
        $this->applyToUser($user, $email);
    }

    private function applyToUser(User $user, string $email): void
    {
        // User's saving hook moves a username that was the old email.
        $user->forceFill(['email' => $email])->save();
    }

    /** ContactDetail saved: copy its work email to the employee's account. */
    public static function syncFromContact(ContactDetail $contact): void
    {
        $email = self::normalize($contact->work_email);
        $user = $contact->employee?->userAccount;

        // Clearing the contact copy never clears a login (change() refuses that for HR).
        if (self::$syncing || !$email || !$user || self::normalize($user->email) === $email) {
            return;
        }

        self::copying(fn () => $user->forceFill(['email' => $email])->save());
    }

    /** User saved: copy their email to their employee's contact details. */
    public static function syncFromUser(User $user): void
    {
        $email = self::normalize($user->email);
        $contact = $user->employee_id ? Employee::find($user->employee_id)?->contactDetail : null;

        if (self::$syncing || !$email || !$contact || self::normalize($contact->work_email) === $email) {
            return;
        }

        self::copying(fn () => $contact->forceFill(['work_email' => $email])->save());
    }

    private static function copying(callable $write): void
    {
        self::$syncing = true;
        try {
            $write();
        } finally {
            self::$syncing = false;
        }
    }
}
