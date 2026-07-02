<?php

declare(strict_types=1);

namespace RoboJackSparrow\Image;

use RoboJackSparrow\Core\Logger;
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
        private Logger $logger
    ) {
    }

    public function generate(ImageRequest $request): ImageResult
    {
        $lastError = null;

        foreach ($this->providers as $provider) {
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
}
