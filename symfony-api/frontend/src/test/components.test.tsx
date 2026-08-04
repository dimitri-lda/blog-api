import { render,screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ProductCard } from '../components';

test('product card links to the product route',()=>{render(<MemoryRouter><ProductCard product={{id:1,name:'Cloudswift 4',slug:'cloudswift-4',brand:'On',description:'Runner',price_cents:16900,compare_at_price_cents:null,image_url:'/shoe.jpg',featured:true,active:true,category:{id:1,name:'Running',slug:'running',image_url:null},variants:[]}}/></MemoryRouter>);expect(screen.getByRole('link')).toHaveAttribute('href','/shop/cloudswift-4');expect(screen.getByText('€169.00')).toBeInTheDocument();});
