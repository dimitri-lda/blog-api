import { createContext,useContext,useEffect,useMemo,useState,type ReactNode } from 'react';
import { api,bootstrapAuth,setAccessToken } from './api';
import type { User } from './types';

type AuthValue={user:User|null;ready:boolean;login:(email:string,password:string)=>Promise<void>;register:(data:Record<string,unknown>)=>Promise<void>;logout:()=>Promise<void>;refreshUser:()=>Promise<void>};
const AuthContext=createContext<AuthValue|null>(null);
export function AuthProvider({children}:{children:ReactNode}){const[user,setUser]=useState<User|null>(null);const[ready,setReady]=useState(false);const refreshUser=async()=>{const r=await api.get<{data:User}>('/auth/me');setUser(r.data);};useEffect(()=>{bootstrapAuth().then(ok=>ok?refreshUser():undefined).finally(()=>setReady(true));},[]);const value=useMemo<AuthValue>(()=>({user,ready,login:async(email,password)=>{const r=await api.post<{data:{access_token:string;user:User}}>('/auth/login',{email,password});setAccessToken(r.data.access_token);setUser(r.data.user);},register:async data=>{const r=await api.post<{data:{access_token:string;user:User}}>('/auth/register',data);setAccessToken(r.data.access_token);setUser(r.data.user);},logout:async()=>{await api.post('/auth/logout');setAccessToken(null);setUser(null);},refreshUser}),[user,ready]);return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>}
export const useAuth=()=>{const value=useContext(AuthContext);if(!value)throw new Error('AuthProvider missing');return value;};
