export type User={id:number;name:string;email:string;roles:string[];email_verified:boolean};
export type Category={id:number;name:string;slug:string;image_url:string|null;products_count?:number};
export type Variant={id:number;name:string;sku:string;price_cents:number|null;stock:number};
export type Product={id:number;name:string;slug:string;brand:string;description:string;price_cents:number;compare_at_price_cents:number|null;image_url:string|null;featured:boolean;active:boolean;category:Category;variants:Variant[];images?:{id:number;url:string;position:number}[]};
export type CartItem={id:number;quantity:number;variant_id:number;variant_name:string;name:string;image_url:string|null;unit_price_cents:number;line_total_cents:number};
export type Cart={items:CartItem[];subtotal_cents:number;count:number};
export type Order={id:number;number:string;email:string;phone:string;status:string;delivery_method:string;subtotal_cents:number;delivery_cents:number;total_cents:number;currency:string;created_at:string;address?:Record<string,string|null>;items?:Array<{id:number;name:string;variant_name:string;unit_price_cents:number;quantity:number;line_total_cents:number}>;customer?:User|null};
export type ApiError={message:string;errors:Record<string,string[]>};
export const money=(cents:number)=>new Intl.NumberFormat('en-IE',{style:'currency',currency:'EUR'}).format(cents/100);
