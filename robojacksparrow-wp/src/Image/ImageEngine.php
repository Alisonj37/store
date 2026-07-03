<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image;

use RoboJackSparrow\Core\Logger;
use RoboJackSparrow\Database\Repositories\SettingRepository;
use RoboJackSparrow\Image\Contracts\ImageProviderInterface;
use RoboJackSparrow\Image\Dto\ImageRequest;
use RoboJackSparrow\Image\Dto\ImageResult;
use Throwable;

/**
 * Orquestra a cascata de providers de imagem: kei.ia -> Replicate (Flux
 * Schnell) -> Unsplash -> Pexels -> Pixabay. Tenta cada um em ordem ate o
 * primeiro sucesso; Pollinations foi removido por decisao do projeto.
 */
class ImageEngine
{
    /**
     * @param ImageProviderInterface[] $providers Em ordem de preferencia da cascata.
     */
    public function __construct(
        private array $providers,
        private Logger $logger,
        private ?SettingRepository $settings = null
    ) {
    }

    /**
     * @param ?string $preferredProvider Article-level override (e.g. the
     *     'assigned_image_source' column, when not 'auto'). Wins over the
     *     global 'rjs_preferred_image_provider' setting. When it matches a
     *     configured provider, that provider is tried first; the rest of
     *     the cascade still runs, in its normal order, as fallback if the
     *     preferred one fails.
     */
    public function generate(ImageRequest $request, ?string $preferredProvider = null): ImageResult
    {
        $lastError = null;

        foreach ($this->orderedProviders($this->resolvePreferredProvider($preferredProvider)) as $provider) {
            try {
                $result = $provider->generate($request);

                $this->logger->info('Image generated successfully', [
                    'provider' => $provider->getName(),
                    'bytes'    => strlen($result->getBinaryData()),
                ]);

                return $result;
            } catch (Throwable $e) {
                $lastError = $e;
                $this->logger->warning('Image provider failed, trying next', [
                    'provider' => $provider->getName(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        throw new ImageException('All image providers failed. Last error: ' . ($lastError?->getMessage() ?? 'unknown'));
    }

    /**
     * @return ImageProviderInterface[]
     */
    private function orderedProviders(?string $preferredProvider): array
    {
        if ($preferredProvider === null || trim($preferredProvider) === '') {
            return $this->providers;
        }

        $preferred = [];
        $rest = [];

        foreach ($this->providers as $provider) {
            if ($provider->getName() === $preferredProvider) {
                $preferred[] = $provider;
            } else {
                $rest[] = $provider;
            }
        }

        return [...$preferred, ...$rest];
    }

    private function resolvePreferredProvider(?string $override): ?string
    {
        if ($override !== null && trim($override) !== '') {
            return $override;
        }

        if ($this->settings === null) {
            return null;
        }

        $global = trim((string) $this->settings->get('rjs_preferred_image_provider', ''));

        return $global !== '' ? $global : null;
    }
}
