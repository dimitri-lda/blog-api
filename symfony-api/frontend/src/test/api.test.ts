import { afterEach,expect,test,vi } from 'vitest';
import { request,setAccessToken } from '../api';

afterEach(()=>{vi.unstubAllGlobals();setAccessToken(null)});
test('performs one refresh and retries a protected request',async()=>{const fetchMock=vi.fn().mockResolvedValueOnce(new Response(JSON.stringify({message:'Expired',errors:{}}),{status:401,headers:{'Content-Type':'application/json'}})).mockResolvedValueOnce(new Response(JSON.stringify({data:{access_token:'new-token'}}),{status:200,headers:{'Content-Type':'application/json'}})).mockResolvedValueOnce(new Response(JSON.stringify({data:{id:1}}),{status:200,headers:{'Content-Type':'application/json'}}));vi.stubGlobal('fetch',fetchMock);const result=await request<{data:{id:number}}>('/profile');expect(result.data.id).toBe(1);expect(fetchMock).toHaveBeenCalledTimes(3);expect((fetchMock.mock.calls[2][1].headers as Headers).get('Authorization')).toBe('Bearer new-token');});
