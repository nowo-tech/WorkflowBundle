<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\WorkflowBundle\Service\WorkflowLabelTranslator;

/**
 * Translatable label of a place or a transition (since 1.16): a translation key and its domain. The plain label (then
 * the name) is the fallback when no key is set or the key has no translation ({@see WorkflowLabelTranslator}).
 *
 * Columns `label_key` (string 255, nullable) and `label_domain` (string 128, nullable; null = `messages`).
 *
 * @internal used by the bundle entities only
 */
trait LabelTranslationTrait
{
    #[ORM\Column(name: 'label_key', type: Types::STRING, length: 255, nullable: true)]
    private ?string $labelKey = null;

    #[ORM\Column(name: 'label_domain', type: Types::STRING, length: 128, nullable: true)]
    private ?string $labelDomain = null;

    /**
     * The translation key of the label (null = the plain label is shown).
     */
    public function getLabelKey(): ?string
    {
        return $this->labelKey;
    }

    public function setLabelKey(?string $labelKey): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->labelKey = $labelKey === null || trim($labelKey) === '' ? null : trim($labelKey);

        return $this;
    }

    /**
     * The translation domain of the label key (null = `messages`).
     */
    public function getLabelDomain(): ?string
    {
        return $this->labelDomain;
    }

    public function setLabelDomain(?string $labelDomain): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->labelDomain = $labelDomain === null || trim($labelDomain) === '' ? null : trim($labelDomain);

        return $this;
    }

    /**
     * Sets the translation key and domain of the label at once.
     */
    public function setLabelTranslation(?string $key, ?string $domain = null): self
    {
        return $this->setLabelKey($key)->setLabelDomain($domain);
    }
}
