import './app.css';

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createBrowserRouter, RouterProvider } from 'react-router-dom';
import { AppProvider } from '@/app/AppContext';
import Home from '@/pages/Home';
import Catalog from '@/pages/Catalog';
import Product from '@/pages/Product';
import Cart from '@/pages/Cart';
import Checkout from '@/pages/Checkout';
import Order from '@/pages/Order';
import Login from '@/pages/Login';
import Register from '@/pages/Register';
import ForgotPassword from '@/pages/ForgotPassword';
import ResetPassword from '@/pages/ResetPassword';
import Profile from '@/pages/Profile';
import Dashboard from '@/pages/Dashboard';
import OrdersIndex from '@/pages/admin/OrdersIndex';
import OrderShow from '@/pages/admin/OrderShow';
import NotFound from '@/pages/NotFound';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: { refetchOnWindowFocus: false, retry: 1 },
    },
});

const router = createBrowserRouter([
    { path: '/', element: <Home /> },
    { path: '/shop', element: <Catalog /> },
    { path: '/shop/:slug', element: <Product /> },
    { path: '/cart', element: <Cart /> },
    { path: '/checkout', element: <Checkout /> },
    { path: '/orders/:number', element: <Order /> },
    { path: '/login', element: <Login /> },
    { path: '/register', element: <Register /> },
    { path: '/forgot-password', element: <ForgotPassword /> },
    { path: '/reset-password', element: <ResetPassword /> },
    { path: '/profile', element: <Profile /> },
    { path: '/dashboard', element: <Dashboard /> },
    { path: '/admin/orders', element: <OrdersIndex /> },
    { path: '/admin/orders/:id', element: <OrderShow /> },
    { path: '*', element: <NotFound /> },
]);

createRoot(document.getElementById('app')).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <AppProvider>
                <RouterProvider router={router} />
            </AppProvider>
        </QueryClientProvider>
    </StrictMode>,
);
