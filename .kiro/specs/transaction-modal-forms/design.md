# Design Document: Transaction Module Modal Forms

## Overview

This design document specifies the unified modal system for the Transaction Module, covering Staff, Manager, and Admin roles. All modals share a consistent design language with the color scheme based on **Petron Blue (#002F70)** as the primary brand color.

### Design Principles

1. **Unified Visual Language** - Same colors, typography, spacing across all modals
2. **Clear Hierarchy** - Important information stands out through size and color
3. **Progressive Disclosure** - Show relevant fields based on context
4. **Instant Feedback** - Real-time validation and calculation
5. **Mobile-First** - Responsive design that works on all screen sizes
6. **Accessible** - WCAG AA compliant with keyboard navigation

---

## Color System

### Primary Colors
```css
--tm-primary-blue: #002F70;      /* Petron brand blue - headers, primary actions */
--tm-white: #FFFFFF;              /* Modal background, text on dark */
--tm-light-gray: #F8FAFC;         /* Section backgrounds, subtle fills */
--tm-border-gray: #E2E8F0;        /* Borders, dividers */
```

### Text Colors
```css
--tm-text-dark: #1E293B;          /* Body text, main content */
--tm-text-muted: #64748B;         /* Labels, secondary text */
--tm-text-label: #475569;         /* Form labels */
```

### Action Colors
```css
--tm-success-green: #059669;      /* Approve, success actions */
--tm-danger-red: #DC2626;         /* Reject, delete actions */
--tm-warning-yellow: #F59E0B;     /* Adjust, warning actions */
--tm-info-blue: #3B82F6;          /* Info badges, links */
```

### Status Badge Colors
```css
--tm-status-paid: #D1FAE5;        /* Background for Paid status */
--tm-status-paid-text: #065F46;   /* Text for Paid status */
--tm-status-partial: #FEF3C7;     /* Background for Partial status */
--tm-status-partial-text: #92400E; /* Text for Partial status */
--tm-status-utang: #FEE2E2;       /* Background for Utang status */
--tm-status-utang-text: #991B1B;  /* Text for Utang status */
```

---

## Typography System

### Font Family
```css
font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
```

### Font Sizes

```css
--tm-text-xl: 24px;              /* Page titles (not used in modals) */
--tm-text-lg: 18px;              /* Modal titles */
--tm-text-md: 16px;              /* Section headings */
--tm-text-base: 14px;            /* Body text, input text */
--tm-text-sm: 13px;              /* Labels */
--tm-text-xs: 12px;              /* Helper text, badges */
```

### Font Weights
```css
--tm-font-normal: 400;           /* Body text */
--tm-font-medium: 500;           /* Emphasized text */
--tm-font-semibold: 600;         /* Labels, section headers */
--tm-font-bold: 700;             /* Modal titles, important numbers */
```

---

## Spacing System

```css
--tm-space-xs: 4px;              /* Minimal spacing */
--tm-space-sm: 8px;              /* Tight spacing */
--tm-space-md: 12px;             /* Standard spacing */
--tm-space-lg: 16px;             /* Comfortable spacing */
--tm-space-xl: 20px;             /* Section spacing */
--tm-space-2xl: 24px;            /* Major section spacing */
--tm-space-3xl: 32px;            /* Large gaps */
```

