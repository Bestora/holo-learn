// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="E-Mail bestätigen" />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    Ein neuer Bestätigungslink ist an deine E-Mail-Adresse
                    unterwegs.
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            Bestätigungs-E-Mail erneut senden
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Abmelden
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'E-Mail bestätigen',
    description:
        'Bitte bestätige deine E-Mail-Adresse über den Link, den wir dir gerade geschickt haben.',
};
