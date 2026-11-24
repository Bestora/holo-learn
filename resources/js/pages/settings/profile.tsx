import { Form, Head, router, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { edit } from '@/routes/profile';
import { update as updateSpecials } from '@/routes/specials';
import type { Auth } from '@/types';
import { send } from '@/routes/verification';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile"
                    description="Update your name and email address"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Email address"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Your email address is unverified.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Click here to re-send the
                                                verification email.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                A new verification link has been
                                                sent to your email address.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <SpecialsSetting enabled={auth.user.specials_enabled === true} />

            <DeleteUser />
        </>
    );
}

/** Bonus items (special readings and rare characters) on or off. */
function SpecialsSetting({ enabled }: { enabled: boolean }) {
    const toggle = () =>
        router.put(
            updateSpecials.url(),
            { enabled: !enabled },
            { preserveScroll: true },
        );

    return (
        <div className="space-y-4">
            <Heading
                variant="small"
                title="Learning"
                description="What shows up in your lessons"
            />
            <div className="flex items-start justify-between gap-4 rounded-2xl border border-border bg-card p-4">
                <div>
                    <p id="specials-label" className="font-semibold">
                        Special items
                    </p>
                    <p
                        id="specials-description"
                        className="text-sm text-muted-foreground"
                    >
                        Extra characters and words beyond the basics, marked
                        “Special” in lessons.
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    aria-checked={enabled}
                    aria-labelledby="specials-label"
                    aria-describedby="specials-description"
                    onClick={toggle}
                    className={cn(
                        'relative h-8 w-14 shrink-0 rounded-full transition-colors focus-visible:ring-4 focus-visible:ring-ring/40 focus-visible:outline-none',
                        enabled ? 'bg-penlight' : 'bg-muted-foreground/40',
                    )}
                >
                    <span
                        className={cn(
                            'absolute top-1 left-1 size-6 rounded-full bg-white shadow transition-transform',
                            enabled && 'translate-x-6',
                        )}
                    />
                </button>
            </div>
        </div>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
