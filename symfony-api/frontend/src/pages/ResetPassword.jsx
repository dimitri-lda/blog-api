import { useNavigate, useSearchParams } from 'react-router-dom';
import InputError from '@/components/InputError';
import InputLabel from '@/components/InputLabel';
import PrimaryButton from '@/components/PrimaryButton';
import TextInput from '@/components/TextInput';
import GuestLayout from '@/layouts/GuestLayout';
import { useForm } from '@/lib/useForm';

export default function ResetPassword() {
    const [searchParams] = useSearchParams();
    const navigate = useNavigate();
    const { data, setData, post, processing, errors, reset } = useForm({
        token: searchParams.get('token') ?? '',
        email: searchParams.get('email') ?? '',
        password: '',
        password_confirmation: '',
    });

    const submit = e => {
        e.preventDefault();
        post('/auth/reset-password', {
            onSuccess: () => navigate('/login', { state: { passwordReset: true } }),
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return <GuestLayout title="Reset Password">
        <form onSubmit={submit}>
            <div>
                <InputLabel htmlFor="email" value="Email" />
                <TextInput
                    id="email"
                    type="email"
                    name="email"
                    value={data.email}
                    className="mt-1 block w-full"
                    autoComplete="username"
                    onChange={e => setData('email', e.target.value)}
                />
                <InputError message={errors.email} className="mt-2" />
            </div>
            <div className="mt-4">
                <InputLabel htmlFor="password" value="Password" />
                <TextInput
                    id="password"
                    type="password"
                    name="password"
                    value={data.password}
                    className="mt-1 block w-full"
                    autoComplete="new-password"
                    isFocused={true}
                    onChange={e => setData('password', e.target.value)}
                />
                <InputError message={errors.password} className="mt-2" />
            </div>
            <div className="mt-4">
                <InputLabel
                    htmlFor="password_confirmation"
                    value="Confirm Password"
                />
                <TextInput
                    type="password"
                    id="password_confirmation"
                    name="password_confirmation"
                    value={data.password_confirmation}
                    className="mt-1 block w-full"
                    autoComplete="new-password"
                    onChange={e => setData('password_confirmation', e.target.value)}
                />
                <InputError
                    message={errors.password_confirmation}
                    className="mt-2"
                />
            </div>
            {errors.token && <InputError message={errors.token} className="mt-2" />}
            <div className="mt-4 flex items-center justify-end">
                <PrimaryButton className="ms-4" disabled={processing}>
                    Reset Password
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>;
}
