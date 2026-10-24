<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

use App\Access\Core\Domain\TicketBundle;
use App\Access\Core\Domain\TicketMailerInterface;
use App\Access\Core\Domain\ValueObject\EventDisplayInfo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Twig\Environment;

/**
 * Sends one email per bundle, with one inline QR code per ticket. A QR code encodes the token only.
 */
final readonly class MailerTicketMailer implements TicketMailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private QrCodeRendererInterface $qrCodes,
        #[Autowire(param: 'app.mailer_sender')]
        private string $sender,
    ) {
    }

    public function send(TicketBundle $bundle, EventDisplayInfo $event): void
    {
        $email = new Email()
            ->from($this->sender)
            ->to($bundle->buyerEmail()->toString())
            ->subject(\sprintf('Vos billets — %s', $event->title()));

        $tickets = [];
        foreach ($bundle->tickets() as $position => $ticket) {
            $image = \sprintf('ticket-%d.png', $position + 1);
            $email->addPart(new DataPart($this->qrCodes->png($ticket->admissionRight()->token()->toString()), $image, 'image/png')->asInline());
            $tickets[] = ['image' => $image, 'tier' => $event->labelOf($ticket->priceTierId())];
        }

        $email->html($this->twig->render('email/tickets.html.twig', ['event' => $event, 'tickets' => $tickets]));

        $this->mailer->send($email);
    }
}
