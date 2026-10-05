import { submitOrder } from "@/lib/runtime";
import { useState, useEffect } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Separator } from "@/components/ui/separator";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ShoppingCart, Truck, CreditCard, Banknote, ChevronLeft, CheckCircle, MapPin, Phone, User, Mail } from "lucide-react";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { useCart } from "@/contexts/CartContext";
import { formatPKR } from "@/data/products";

const cities = [
  "Lahore", "Karachi", "Islamabad", "Rawalpindi", "Faisalabad", "Multan",
  "Peshawar", "Quetta", "Sialkot", "Gujranwala", "Hyderabad", "Bahawalpur",
];

const Checkout = () => {
  const { items, totalPrice, totalItems, clearCart } = useCart();
  const navigate = useNavigate();
  const [paymentMethod, setPaymentMethod] = useState("cod");
  const [orderPlaced, setOrderPlaced] = useState(false);
  const [formData, setFormData] = useState({
    name: "", email: "", phone: "", address: "", city: "Lahore", notes: "",
  });

  useEffect(() => {
    document.title = "Checkout — WOODEX Pakistan";
  }, []);

  const deliveryFee = totalPrice >= 50000 ? 0 : 2500;
  const grandTotal = totalPrice + deliveryFee;

  const handleChange = (field: string, value: string) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    // → shared backend: appears on the dashboard operations board
    await submitOrder({
      customer: formData.name || "Website checkout",
      contact: formData.email,
      items: items.map((i) => ({ name: (i as { name?: string }).name, price: i.price, qty: i.quantity })),
      total: grandTotal,
      source: "Storefront checkout",
      note: `${formData.address}, ${formData.city}${formData.notes ? " — " + formData.notes : ""}`,
    });
    setOrderPlaced(true);
    clearCart();
    window.scrollTo(0, 0);
  };

  if (orderPlaced) {
    return (
      <div className="min-h-screen flex flex-col">
        <Header />
        <main className="flex-1 flex items-center justify-center py-20">
          <div className="text-center max-w-md mx-auto px-4">
            <div className="w-20 h-20 rounded-full bg-accent/10 flex items-center justify-center mx-auto mb-6">
              <CheckCircle className="h-10 w-10 text-accent" />
            </div>
            <h1 className="text-3xl font-bold mb-3">Order Placed Successfully!</h1>
            <p className="text-muted-foreground mb-2">
              Thank you for your order. Our team will contact you within 24 hours to confirm delivery details.
            </p>
            <p className="text-sm text-muted-foreground mb-8">
              {paymentMethod === "cod"
                ? "Payment will be collected on delivery."
                : "Our team will share bank details for transfer."}
            </p>
            <div className="flex flex-wrap gap-3 justify-center">
              <Button className="bg-accent hover:bg-accent/90 text-accent-foreground" asChild>
                <Link to="/shop">Continue Shopping</Link>
              </Button>
              <Button variant="outline" asChild>
                <Link to="/">Back to Home</Link>
              </Button>
            </div>
          </div>
        </main>
        <Footer />
      </div>
    );
  }

  if (items.length === 0) {
    return (
      <div className="min-h-screen flex flex-col">
        <Header />
        <main className="flex-1 flex items-center justify-center py-20">
          <div className="text-center">
            <ShoppingCart className="h-12 w-12 text-muted-foreground/40 mx-auto mb-4" />
            <h1 className="text-2xl font-bold mb-2">Your cart is empty</h1>
            <p className="text-muted-foreground mb-6">Add products to your cart before checking out.</p>
            <Button className="bg-accent hover:bg-accent/90 text-accent-foreground" asChild>
              <Link to="/shop">Browse Products</Link>
            </Button>
          </div>
        </main>
        <Footer />
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      <main className="flex-1">
        {/* Hero */}
        <section className="bg-primary text-primary-foreground py-8">
          <div className="container mx-auto px-4">
            <div className="flex items-center gap-2 mb-2">
              <Link to="/shop" className="text-primary-foreground/60 hover:text-primary-foreground text-sm flex items-center gap-1">
                <ChevronLeft className="h-3.5 w-3.5" /> Back to Shop
              </Link>
            </div>
            <h1 className="text-3xl lg:text-4xl font-black">Checkout</h1>
          </div>
        </section>

        <section className="py-10">
          <div className="container mx-auto px-4">
            <form onSubmit={handleSubmit}>
              <div className="grid lg:grid-cols-3 gap-8">
                {/* Left: Form */}
                <div className="lg:col-span-2 space-y-6">
                  {/* Delivery Info */}
                  <Card>
                    <CardHeader>
                      <CardTitle className="flex items-center gap-2 text-lg">
                        <MapPin className="h-5 w-5 text-accent" /> Delivery Information
                      </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                      <div className="grid md:grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                          <Label htmlFor="name">Full Name *</Label>
                          <Input id="name" placeholder="Muhammad Ali" required value={formData.name} onChange={(e) => handleChange("name", e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="phone">Phone Number *</Label>
                          <Input id="phone" type="tel" placeholder="+92 300 1234567" required value={formData.phone} onChange={(e) => handleChange("phone", e.target.value)} />
                        </div>
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor="email">Email</Label>
                        <Input id="email" type="email" placeholder="your@email.com" value={formData.email} onChange={(e) => handleChange("email", e.target.value)} />
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor="address">Delivery Address *</Label>
                        <Textarea id="address" placeholder="House/Office #, Street, Area, City" required value={formData.address} onChange={(e) => handleChange("address", e.target.value)} />
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor="city">City *</Label>
                        <select
                          id="city"
                          value={formData.city}
                          onChange={(e) => handleChange("city", e.target.value)}
                          className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                          required
                        >
                          {cities.map((c) => <option key={c} value={c}>{c}</option>)}
                        </select>
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor="notes">Order Notes (optional)</Label>
                        <Textarea id="notes" placeholder="Special instructions for delivery..." value={formData.notes} onChange={(e) => handleChange("notes", e.target.value)} />
                      </div>
                    </CardContent>
                  </Card>

                  {/* Payment Method */}
                  <Card>
                    <CardHeader>
                      <CardTitle className="flex items-center gap-2 text-lg">
                        <CreditCard className="h-5 w-5 text-accent" /> Payment Method
                      </CardTitle>
                    </CardHeader>
                    <CardContent>
                      <RadioGroup value={paymentMethod} onValueChange={setPaymentMethod} className="space-y-3">
                        <label className={`flex items-center gap-4 p-4 border rounded-sm cursor-pointer transition-colors ${paymentMethod === "cod" ? "border-accent bg-accent/5" : "border-border hover:border-accent/50"}`}>
                          <RadioGroupItem value="cod" id="cod" />
                          <Banknote className="h-5 w-5 text-accent" />
                          <div>
                            <p className="font-semibold text-sm">Cash on Delivery (COD)</p>
                            <p className="text-xs text-muted-foreground">Pay when your furniture is delivered & installed</p>
                          </div>
                        </label>
                        <label className={`flex items-center gap-4 p-4 border rounded-sm cursor-pointer transition-colors ${paymentMethod === "bank" ? "border-accent bg-accent/5" : "border-border hover:border-accent/50"}`}>
                          <RadioGroupItem value="bank" id="bank" />
                          <CreditCard className="h-5 w-5 text-accent" />
                          <div>
                            <p className="font-semibold text-sm">Bank Transfer</p>
                            <p className="text-xs text-muted-foreground">Transfer to WOODEX bank account — details will be shared after order</p>
                          </div>
                        </label>
                      </RadioGroup>
                    </CardContent>
                  </Card>
                </div>

                {/* Right: Order Summary */}
                <div>
                  <Card className="sticky top-28">
                    <CardHeader>
                      <CardTitle className="flex items-center gap-2 text-lg">
                        <ShoppingCart className="h-5 w-5 text-accent" /> Order Summary
                      </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                      {items.map((item) => (
                        <div key={`${item.id}-${item.color}`} className="flex gap-3">
                          {item.image && (
                            <div className="w-12 h-12 rounded-sm overflow-hidden flex-shrink-0 bg-muted">
                              <img src={item.image} alt={item.name} className="w-full h-full object-cover" />
                            </div>
                          )}
                          <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium line-clamp-1">{item.name}</p>
                            {item.color && <p className="text-xs text-muted-foreground">{item.color}</p>}
                            <p className="text-xs text-muted-foreground">Qty: {item.quantity}</p>
                          </div>
                          <p className="text-sm font-semibold whitespace-nowrap">{formatPKR(item.price * item.quantity)}</p>
                        </div>
                      ))}

                      <Separator />

                      <div className="space-y-2 text-sm">
                        <div className="flex justify-between">
                          <span className="text-muted-foreground">Subtotal ({totalItems} items)</span>
                          <span className="font-medium">{formatPKR(totalPrice)}</span>
                        </div>
                        <div className="flex justify-between">
                          <span className="text-muted-foreground">Delivery</span>
                          <span className="font-medium">{deliveryFee === 0 ? <span className="text-accent">FREE</span> : formatPKR(deliveryFee)}</span>
                        </div>
                        {deliveryFee > 0 && (
                          <p className="text-xs text-muted-foreground">Free delivery on orders above PKR 50,000</p>
                        )}
                        <div className="flex justify-between items-center">
                          <span className="text-muted-foreground">Assembly</span>
                          <span className="text-accent text-xs font-semibold">FREE</span>
                        </div>
                      </div>

                      <Separator />

                      <div className="flex justify-between items-center">
                        <span className="font-bold text-lg">Total</span>
                        <span className="font-bold text-xl text-accent">{formatPKR(grandTotal)}</span>
                      </div>

                      <Button type="submit" className="w-full bg-accent hover:bg-accent/90 text-accent-foreground font-semibold text-base h-12">
                        {paymentMethod === "cod" ? "Place Order — Cash on Delivery" : "Place Order — Bank Transfer"}
                      </Button>

                      <div className="flex items-start gap-2 text-xs text-muted-foreground">
                        <Truck className="h-3.5 w-3.5 mt-0.5 text-accent flex-shrink-0" />
                        <span>Professional delivery & assembly included. Our team will contact you to schedule delivery.</span>
                      </div>
                    </CardContent>
                  </Card>
                </div>
              </div>
            </form>
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default Checkout;
