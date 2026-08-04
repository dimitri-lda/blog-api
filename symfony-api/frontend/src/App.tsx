import { Navigate,Route,Routes } from 'react-router-dom';
import { AdminLayout,GuestLayout,Protected,StoreLayout } from './components';
import { CartPage,CheckoutPage,HomePage,OrderPage,ProductPage,ShopPage } from './pages/store';
import { ForgotPage,LoginPage,RegisterPage,ResetPage,VerifyPage } from './pages/auth';
import { DashboardPage,ProfilePage } from './pages/account';
import { AdminOrderPage,AdminOrdersPage } from './pages/admin';

export default function App(){return <Routes><Route element={<StoreLayout/>}><Route index element={<HomePage/>}/><Route path="shop" element={<ShopPage/>}/><Route path="shop/:slug" element={<ProductPage/>}/><Route path="cart" element={<CartPage/>}/><Route path="checkout" element={<CheckoutPage/>}/><Route path="orders/:number" element={<OrderPage/>}/><Route element={<Protected/>}><Route path="dashboard" element={<DashboardPage/>}/><Route path="profile" element={<ProfilePage/>}/></Route></Route><Route element={<GuestLayout/>}><Route path="login" element={<LoginPage/>}/><Route path="register" element={<RegisterPage/>}/><Route path="forgot-password" element={<ForgotPage/>}/><Route path="reset-password" element={<ResetPage/>}/><Route path="verify-email" element={<VerifyPage/>}/></Route><Route element={<Protected admin/>}><Route element={<AdminLayout/>}><Route path="admin/orders" element={<AdminOrdersPage/>}/><Route path="admin/orders/:id" element={<AdminOrderPage/>}/></Route></Route><Route path="*" element={<Navigate to="/" replace/>}/></Routes>}
