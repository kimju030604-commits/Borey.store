import { createContext, useContext, useState, useCallback } from 'react'

const StoreContext = createContext(null)

export function StoreProvider({ children }) {
  const [cart, setCart] = useState(() => {
    try { return JSON.parse(localStorage.getItem('borey_cart') || '[]') }
    catch { return [] }
  })

  const persist = (next) => {
    setCart(next)
    localStorage.setItem('borey_cart', JSON.stringify(next))
  }

  const addToCart = useCallback((product, stockLimit = Infinity) => {
    setCart(prev => {
      const found = prev.find(i => i.id === product.id)
      let next
      if (found) {
        if (found.qty + 1 > stockLimit) return prev
        next = prev.map(i => i.id === product.id ? { ...i, qty: i.qty + 1 } : i)
      } else {
        if (stockLimit === 0) return prev
        next = [...prev, { ...product, qty: 1 }]
      }
      localStorage.setItem('borey_cart', JSON.stringify(next))
      return next
    })
  }, [])

  const removeFromCart = useCallback((id) => {
    setCart(prev => {
      const next = prev.filter(i => i.id !== id)
      localStorage.setItem('borey_cart', JSON.stringify(next))
      return next
    })
  }, [])

  const updateQty = useCallback((id, delta, stockLimit = Infinity) => {
    setCart(prev => {
      const next = prev.reduce((acc, item) => {
        if (item.id !== id) return [...acc, item]
        const qty = item.qty + delta
        if (qty <= 0) return acc
        if (qty > stockLimit) return [...acc, item]
        return [...acc, { ...item, qty }]
      }, [])
      localStorage.setItem('borey_cart', JSON.stringify(next))
      return next
    })
  }, [])

  const clearCart = useCallback(() => {
    setCart([])
    localStorage.removeItem('borey_cart')
  }, [])

  const cartCount = cart.reduce((a, b) => a + b.qty, 0)
  const cartTotal = cart.reduce((a, b) => a + b.price * b.qty, 0)

  return (
    <StoreContext.Provider value={{ cart, addToCart, removeFromCart, updateQty, clearCart, cartCount, cartTotal }}>
      {children}
    </StoreContext.Provider>
  )
}

export const useCart = () => useContext(StoreContext)
