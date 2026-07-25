<?php

declare(strict_types=1);

namespace WPQueue\Jobs;

use WPQueue\Contracts\JobInterface;
use WPQueue\Contracts\ShouldQueue;

if (! defined('ABSPATH')) {
    exit;
}

abstract class Job implements JobInterface, ShouldQueue
{
    protected string $id;

    protected string $queue = 'default';

    protected int $attempts = 0;

    protected int $maxAttempts = 3;

    protected int $timeout = 60;

    protected int $delay = 0;

    protected int $createdAt;

    public function __construct()
    {
        $this->id = $this->generateId();
        $this->createdAt = time();
        $this->applyAttributes();
    }

    /**
     * Apply PHP 8 attributes to job properties
     */
    private function applyAttributes(): void
    {
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes();

        foreach ($attributes as $attribute) {
            $name = $attribute->getName();

            if ($name === 'WPQueue\Attributes\Queue') {
                $args = $attribute->getArguments();
                if (! empty($args[0])) {
                    $this->queue = $args[0];
                }
            } elseif ($name === 'WPQueue\Attributes\Timeout') {
                $args = $attribute->getArguments();
                if (! empty($args[0])) {
                    $this->timeout = (int) $args[0];
                }
            } elseif ($name === 'WPQueue\Attributes\Retries') {
                $args = $attribute->getArguments();
                if (! empty($args[0])) {
                    $this->maxAttempts = (int) $args[0];
                }
            }
        }
    }

    abstract public function handle(): void;

    public function getId(): string
    {
        return $this->id;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function onQueue(string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $attempts): static
    {
        $this->maxAttempts = $attempts;

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setTimeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function delay(int $seconds): static
    {
        $this->delay = $seconds;

        return $this;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'class' => static::class,
            'queue' => $this->queue,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'timeout' => $this->timeout,
            'delay' => $this->delay,
            'created_at' => $this->createdAt,
        ];
    }

    public function failed(\Throwable $e): void
    {
        // Override in child classes
    }

    protected function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Serialize all properties including child class properties.
     * Uses Reflection to capture private/protected properties from child classes.
     */
    public function __serialize(): array
    {
        $data = [];
        $reflection = new \ReflectionClass($this);

        // Get all properties from this class and parent classes
        do {
            foreach ($reflection->getProperties() as $property) {
                $property->setAccessible(true);
                $name = $property->getName();

                // Skip uninitialized typed properties
                if ($property->hasType() && ! $property->isInitialized($this)) {
                    continue;
                }

                // Use class name prefix for private properties to avoid conflicts
                if ($property->isPrivate() && $property->getDeclaringClass()->getName() !== self::class) {
                    $key = $property->getDeclaringClass()->getName().'::'.$name;
                } else {
                    $key = $name;
                }
                $data[$key] = $property->getValue($this);
            }
        } while ($reflection = $reflection->getParentClass());

        return $data;
    }

    /**
     * Unserialize all properties including child class properties.
     */
    public function __unserialize(array $data): void
    {
        $reflection = new \ReflectionClass($this);

        // Build a map of all properties
        $properties = [];
        $ref = $reflection;
        do {
            foreach ($ref->getProperties() as $property) {
                $name = $property->getName();
                if ($property->isPrivate() && $property->getDeclaringClass()->getName() !== self::class) {
                    $key = $property->getDeclaringClass()->getName().'::'.$name;
                } else {
                    $key = $name;
                }
                $properties[$key] = $property;
            }
        } while ($ref = $ref->getParentClass());

        // Restore values from serialized data
        foreach ($data as $key => $value) {
            if (isset($properties[$key])) {
                $properties[$key]->setAccessible(true);
                $properties[$key]->setValue($this, $value);
            }
        }

        // Initialize any remaining uninitialized typed properties with defaults
        foreach ($properties as $key => $property) {
            if ($property->hasType() && ! $property->isInitialized($this)) {
                $type = $property->getType();
                if ($type instanceof \ReflectionNamedType && ! $type->allowsNull()) {
                    $default = match ($type->getName()) {
                        'array' => [],
                        'string' => '',
                        'int' => 0,
                        'float' => 0.0,
                        'bool' => false,
                        default => null,
                    };
                    if ($default !== null) {
                        $property->setAccessible(true);
                        $property->setValue($this, $default);
                    }
                }
            }
        }
    }
}
