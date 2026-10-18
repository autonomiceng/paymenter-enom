<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Support;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Throwable;

/** Permission checks, service lookup and notifications shared by the Enom admin screens. */
final class Staff
{
    public const STAFF_SERVICE = 'Paymenter\\Extensions\\Servers\\Enom\\Services\\StaffDomains';
    public const MANAGE = 'admin.enom.manage';
    public const FUND = 'admin.enom.fund';
    public const COSTS = 'admin.enom.costs';
    /** Paymenter's own invoice permission, needed to give account credit back. */
    public const INVOICES = 'admin.invoices.update';

    public static function user(): ?User
    {
        $user = Auth::user();
        return $user instanceof User ? $user : null;
    }

    public static function can(string ...$permissions): bool
    {
        $user = self::user();
        if (!$user) { return false; }
        foreach ($permissions as $permission) {
            if (!$user->hasPermission($permission)) { return false; }
        }
        return true;
    }

    /** Server-side re-check inside every action. */
    public static function require(string ...$permissions): User
    {
        $permissions = array_values(array_unique([self::MANAGE, ...$permissions]));
        abort_unless(self::can(...$permissions), 403);
        return self::user();
    }

    /**
     * Run a staff action and report the result as a Filament notification.
     * Validation and refusal messages from the services are written for staff and shown as-is.
     */
    public static function service(?string $method = null): ?object
    {
        if (!class_exists(self::STAFF_SERVICE)) { return null; }
        if ($method !== null && !method_exists(self::STAFF_SERVICE, $method)) { return null; }
        return app(self::STAFF_SERVICE);
    }

    public static function supports(string $method): bool
    {
        return class_exists(self::STAFF_SERVICE) && method_exists(self::STAFF_SERVICE, $method);
    }

    public static function attempt(callable $work, string $success, string $failure = 'That did not work. Try again, or check the Enom setup page.'): bool
    {
        try {
            $result = $work();
            if ($result instanceof Notification) { $result->send(); return true; }
            $message = is_string($result) && $result !== '' ? $result : $success;
            Notification::make()->title($message)->success()->send();
            return true;
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            Notification::make()->title('You do not have permission to do this.')->danger()->send();
        } catch (InputValidationException $exception) {
            Notification::make()->title(self::firstError($exception->errors) ?? $failure)->danger()->send();
        } catch (\InvalidArgumentException|\RuntimeException|\DomainException $exception) {
            Notification::make()->title(self::clean($exception->getMessage()) ?? $failure)->danger()->send();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title($failure)->danger()->send();
        }
        return false;
    }

    private static function firstError(array $errors): ?string
    {
        foreach ($errors as $error) {
            $message = is_array($error) ? reset($error) : $error;
            if (is_string($message) && $message !== '') { return $message; }
        }
        return null;
    }

    /** Only short, single-line service messages are shown; anything else falls back to generic text. */
    public static function clean(string $message): ?string
    {
        $message = trim($message);
        return $message !== '' && strlen($message) <= 300 && !str_contains($message, "\n") && !str_contains($message, 'SQLSTATE') ? $message : null;
    }

    /** Domains with requests or a domain status that need staff. */
    public static function attentionDomainIds(): array
    {
        return Operation::query()->whereIn('state', Labels::ATTENTION_STATES)->distinct()->pluck('domain_id')
            ->merge(Domain::query()->where('status', 'needs_review')->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
