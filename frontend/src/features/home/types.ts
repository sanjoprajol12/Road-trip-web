import type { Contribution, Destination, RideRoute } from '@/types/api'

// GET /api/v1/home: published sections in order, each with its own fields (config/home.php on the server).
export interface Cta { label: string | null; to: string }
export interface LibraryImage { url: string; alt: string; credit: string; width: number; height: number }
export type Background = { kind: 'bundled'; base: string; widths: number[] } | ({ kind: 'media' } & LibraryImage)

export interface HeroData { eyebrow: string | null; lines: string[]; lead: string | null; primary_cta: Cta | null; secondary_cta: Cta | null; background: Background; photo_credit: string | null }
export interface SlidesData { heading: string | null; items: { heading: string; text: string | null; image: LibraryImage; cta: Cta | null }[] }
export interface LayersData { heading: string; intro: string | null; items: { name: string; text: string }[] }
export interface FeaturedData<T> { heading: string; link_label: string | null; items: T[] }
export interface FreshnessData { heading: string; text: string | null; legend: { term: string; detail: string }[]; caption: string | null }
export interface BannerData { heading: string; text: string | null; image: LibraryImage | null; cta: Cta | null }
export interface TextData { heading: string; body: string }
/** A real published route (or none) and the published destinations a rider can pick. */
export interface PlannerData { heading: string; intro: string | null; route: RideRoute | null; places: { slug: string; name: string }[] }
export interface ToolsData { heading: string; intro: string | null; items: { name: string; text: string; cta: Cta | null }[] }

export type HomeSection = { id: string } & (
  | ({ type: 'hero' } & HeroData)
  | ({ type: 'slides' } & SlidesData)
  | ({ type: 'layers' } & LayersData)
  | ({ type: 'featured_destinations' } & FeaturedData<Destination>)
  | ({ type: 'featured_routes' } & FeaturedData<RideRoute>)
  | ({ type: 'featured_experiences' } & FeaturedData<Contribution>)
  | ({ type: 'freshness' } & FreshnessData)
  | ({ type: 'banner' } & BannerData)
  | ({ type: 'text' } & TextData)
  | ({ type: 'planner' } & PlannerData)
  | ({ type: 'tools' } & ToolsData)
)

export interface MenuItem { label: string; to: string; signed_in_only: boolean }
export interface Notice { id: string; body: string; level: 'info' | 'warning'; link_label: string | null; link_target: string | null }
