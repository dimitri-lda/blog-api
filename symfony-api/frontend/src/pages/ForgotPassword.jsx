import { useState } from 'react';
import InputError from '@/components/InputError';
import PrimaryButton from '@/components/PrimaryButton';
import TextInput from '@/components/TextInput';
import GuestLayout from '@/layouts/GuestLayout';
import { useForm } from '@/lib/useForm';

export default function ForgotPassword() {
    const [status, setStatus] = useState(null);
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = e => {
        e.preventDefault();
        post('/auth/forgot-password', {
            onSuccess: response => setStatus(response?.data?.message ?? null),
        });
    };

    return <GuestLayout title="Forgot Password">
        <div className="mb-4 text-sm text-gray-600">
            Forgot your password? No problem. Just let us know your email
            address and we will email you a password reset link that will
            allow you to choose a new one.
        </div>
        {status && (
            <div className="mb-4 text-sm font-medium text-blue-600">
                {status}
            </div>
        )}
        <form onSubmit={submit}>
            <TextInput
                id="email"
                type="email"
                name="email"
                value={data.email}
                className="mt-1 block w-full"
                isFocused={true}
                onChange={e => setData('email', e.target.value)}
            />
            <InputError message={errors.email} className="mt-2" />
            <div className="mt-4 flex items-center justify-end">
                <PrimaryButton className="ms-4" disabled={processing}>
                    Email Password Reset Link
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>;
}
