import type { ApiError } from './types';

const base=import.meta.env.VITE_API_BASE_URL??'/api';
let accessToken:string|null=null;
let refreshPromise:Promise<boolean>|null=null;
export const setAccessToken=(token:string|null)=>{accessToken=token;};

async function refresh():Promise<boolean>{
  if(!refreshPromise)refreshPromise=fetch(`${base}/auth/refresh`,{method:'POST',credentials:'include'}).then(async response=>{if(!response.ok){accessToken=null;return false;}const json=await response.json();accessToken=json.data.access_token;return true;}).finally(()=>{refreshPromise=null;});
  return refreshPromise;
}

export class RequestError extends Error{constructor(public status:number,public payload:ApiError){super(payload.message);}}

export async function request<T>(path:string,options:RequestInit={},retry=true):Promise<T>{
  const headers=new Headers(options.headers);if(options.body&&!headers.has('Content-Type'))headers.set('Content-Type','application/json');if(accessToken)headers.set('Authorization',`Bearer ${accessToken}`);
  const response=await fetch(`${base}${path}`,{...options,headers,credentials:'include'});
  if(response.status===401&&retry&&!path.startsWith('/auth/')){if(await refresh())return request<T>(path,options,false);}
  if(!response.ok){let payload:ApiError;try{payload=await response.json();}catch{payload={message:'Request failed.',errors:{}};}throw new RequestError(response.status,payload);}
  if(response.status===204)return undefined as T;return response.json() as Promise<T>;
}
export const api={get:<T>(path:string)=>request<T>(path),post:<T>(path:string,data?:unknown)=>request<T>(path,{method:'POST',body:data===undefined?undefined:JSON.stringify(data)}),patch:<T>(path:string,data:unknown)=>request<T>(path,{method:'PATCH',body:JSON.stringify(data)}),put:<T>(path:string,data:unknown)=>request<T>(path,{method:'PUT',body:JSON.stringify(data)}),delete:<T>(path:string,data?:unknown)=>request<T>(path,{method:'DELETE',body:data===undefined?undefined:JSON.stringify(data)})};
export const bootstrapAuth=refresh;
