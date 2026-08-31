<?php declare(strict_types=1);

namespace Ark;

class Ark
{
    /**
     * @var string|null
     */
    protected $naan;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string|null
     */
    protected $qualifier;

    /**
     * @param string|int|null $qualifier
     */
    public function __construct(?string $naan, string $name, $qualifier = null)
    {
        $this->naan = $naan;
        $this->name = $name;
        $this->qualifier = $qualifier === null ? null : (string) $qualifier;
    }

    public function setNaan(?string $naan): self
    {
        $this->naan = $naan;
        return $this;
    }

    public function getNaan(): ?string
    {
        return $this->naan;
    }

    public function setName($name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string|int|null $qualifier
     */
    public function setQualifier($qualifier): self
    {
        $this->qualifier = $qualifier === null ? null : (string) $qualifier;
        return $this;
    }

    public function getQualifier(): ?string
    {
        return $this->qualifier;
    }

    /**
     * The naan may be empty, like in the base used to search arks.
     */
    public function asString(): string
    {
        return sprintf(
            'ark:/%s%s%s',
            $this->naan ? $this->naan . '/' : '',
            $this->name,
            $this->qualifier ? '/' . $this->qualifier : ''
        );
    }

    public function __toString(): string
    {
        return $this->asString();
    }
}
